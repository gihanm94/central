import { ApiError, errorFromResponse } from './errors';
import { pairFromResponse, type TokenPair, type TokenResponse, type TokenStore } from './tokens';

export type Query = Record<string, string | number | boolean | null | undefined>;

export interface RequestOptions {
  query?: Query;
  /** A plain object is sent as JSON; FormData as multipart/form-data. */
  body?: unknown;
  /** false for /auth/login and /auth/refresh. */
  auth?: boolean;
  signal?: AbortSignal;
}

export interface Download {
  bytes: Uint8Array;
  filename: string | null;
  contentType: string | null;
}

export interface ClientOptions {
  /** API root, e.g. https://erp.example.com/api/v1 (no trailing slash). Read on every request. */
  baseUrl: () => string;
  tokens: TokenStore;
  fetch?: typeof fetch;
  /** "en" | "th": the language of messages before sign-in. */
  language?: () => string;
  /** The sign-in is over (401 invalid_token, session_ended, no_api_access, unauthenticated). Tokens are already cleared. */
  onSessionEnded?: (error: ApiError) => void;
  /** A module answered 503 module_unavailable. */
  onModuleUnavailable?: (module: string) => void;
  /** Development logging of method, path (without query), status and request id. Never bodies or headers. */
  log?: (line: string) => void;
  now?: () => number;
  sleep?: (ms: number) => Promise<void>;
  /** Wait at most this long for a 429 window to pass before failing fast (ms). */
  maxRateWait?: number;
}

/** Refresh a little before the access token runs out, so a request does not race the expiry. */
const EXPIRY_SKEW_MS = 15_000;

/**
 * The one way the app talks to /api/v1.
 *
 * Tokens: the access token lives 15 minutes. When it has run out (or the server answers
 * 401 token_expired) the client refreshes ONCE and retries the request once. Only one refresh
 * runs at a time: every other request waits for it, because the server treats a refresh
 * token that is used twice as stolen and signs the device out.
 */
export class ApiClient {
  private readonly opts: Required<Omit<ClientOptions, 'onSessionEnded' | 'onModuleUnavailable' | 'log' | 'language'>> &
    Pick<ClientOptions, 'onSessionEnded' | 'onModuleUnavailable' | 'log' | 'language'>;
  private pair: TokenPair | null = null;
  private loaded = false;
  private refreshing: Promise<TokenPair> | null = null;
  /** Bumped on sign-in and sign-out, so a refresh that finishes late cannot bring old tokens back. */
  private generation = 0;
  /** After a 429 rate_limited: no request goes out before this time (ms since epoch). */
  private blockedUntil = 0;

  constructor(options: ClientOptions) {
    this.opts = {
      fetch: (...args) => fetch(...args),
      now: () => Date.now(),
      sleep: (ms) => new Promise((r) => setTimeout(r, ms)),
      maxRateWait: 5_000,
      ...options,
    };
  }

  /* ------------------------------------------------------------------ session */

  /** Store a fresh sign-in (from /auth/login). */
  async startSession(response: TokenResponse): Promise<TokenPair> {
    this.generation++;
    const pair = pairFromResponse(response, this.opts.now());
    await this.opts.tokens.save(pair);
    this.pair = pair;
    this.loaded = true;
    return pair;
  }

  /** Forget the tokens (sign-out, or the server ended the sign-in). */
  async clearSession(): Promise<void> {
    this.generation++;
    this.pair = null;
    this.loaded = true;
    this.refreshing = null;
    await this.opts.tokens.clear();
  }

  async hasSession(): Promise<boolean> {
    return (await this.currentPair()) !== null;
  }

  /** The id of this device's sign-in (api_devices.id). */
  async deviceId(): Promise<number | null> {
    return (await this.currentPair())?.deviceId ?? null;
  }

  private async currentPair(): Promise<TokenPair | null> {
    if (!this.loaded) {
      const gen = this.generation;
      const stored = await this.opts.tokens.load();
      if (gen === this.generation && !this.loaded) {
        this.pair = stored;
        this.loaded = true;
      }
    }
    return this.pair;
  }

  /* ----------------------------------------------------------------- requests */

  get<T>(path: string, query?: Query, signal?: AbortSignal): Promise<T> {
    return this.request<T>('GET', path, { query, signal });
  }

  post<T>(path: string, body?: unknown, options: Omit<RequestOptions, 'body'> = {}): Promise<T> {
    return this.request<T>('POST', path, { ...options, body: body ?? {} });
  }

  async request<T>(method: 'GET' | 'POST', path: string, options: RequestOptions = {}): Promise<T> {
    const res = await this.send(method, path, options);
    try {
      return (await res.json()) as T;
    } catch {
      throw new ApiError({ status: res.status, code: 'bad_response', requestId: res.headers.get('X-Request-Id') ?? undefined });
    }
  }

  /** A file answer (PDF) with the Authorization header; errors are the usual JSON errors. */
  async download(path: string, query?: Query, signal?: AbortSignal): Promise<Download> {
    const res = await this.send('GET', path, { query, signal });
    const buffer = await res.arrayBuffer();
    return {
      bytes: new Uint8Array(buffer),
      filename: filenameFromDisposition(res.headers.get('Content-Disposition')),
      contentType: res.headers.get('Content-Type'),
    };
  }

  private async send(method: 'GET' | 'POST', path: string, options: RequestOptions): Promise<Response> {
    const auth = options.auth !== false;
    await this.waitForRateWindow();

    let token: string | null = null;
    if (auth) {
      token = (await this.validPair()).accessToken;
    }
    let res = await this.fetchOnce(method, path, options, token);

    if (auth && res.status === 401) {
      const err = await errorFromResponse(res, this.opts.now());
      if (err.code !== 'token_expired') {
        throw await this.fail(err, path);
      }
      // Refresh once (or take the pair another request just got) and retry once.
      const fresh = await this.pairAfterExpiry(token!);
      res = await this.fetchOnce(method, path, options, fresh.accessToken);
      if (res.status === 401) {
        const again = await errorFromResponse(res, this.opts.now());
        // Still "expired" right after a refresh means the sign-in is gone (e.g. revoked meanwhile).
        throw await this.fail(again.code === 'token_expired' ? new ApiError({ status: 401, code: 'session_ended', message: again.message, requestId: again.requestId }) : again, path);
      }
    }

    if (!res.ok) {
      throw await this.fail(await errorFromResponse(res, this.opts.now()), path);
    }
    return res;
  }

  private async fetchOnce(method: string, path: string, options: RequestOptions, token: string | null): Promise<Response> {
    const url = this.url(path, options.query);
    const headers: Record<string, string> = { Accept: 'application/json' };
    const lang = this.opts.language?.();
    if (lang) headers['Accept-Language'] = lang;
    if (token) headers.Authorization = `Bearer ${token}`;

    let body: BodyInit | undefined;
    if (method === 'POST') {
      if (isFormData(options.body)) {
        body = options.body; // fetch sets the multipart boundary itself
      } else {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.body ?? {});
      }
    }

    let res: Response;
    try {
      res = await this.opts.fetch(url, { method, headers, body, signal: options.signal });
    } catch (e) {
      if ((e as { name?: string } | null)?.name === 'AbortError') throw e;
      this.opts.log?.(`${method} ${stripQuery(path)} → network error`);
      throw new ApiError({ status: 0, code: 'network' });
    }
    this.opts.log?.(`${method} ${stripQuery(path)} → ${res.status} ${res.headers.get('X-Request-Id') ?? ''}`.trim());
    return res;
  }

  /** Builds base + path + query. Paths are API-relative ("/crm/leads"). */
  url(path: string, query?: Query): string {
    const base = this.opts.baseUrl().replace(/\/+$/, '');
    if (!base) throw new ApiError({ status: 0, code: 'no_server' });
    const qs = query
      ? Object.entries(query)
          .filter(([, v]) => v !== undefined && v !== null && v !== '' && v !== false)
          .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v === true ? '1' : String(v))}`)
          .join('&')
      : '';
    return `${base}${path.startsWith('/') ? path : `/${path}`}${qs ? (path.includes('?') ? '&' : '?') + qs : ''}`;
  }

  /* ------------------------------------------------------------------ refresh */

  /** A pair whose access token has not run out yet (refreshing first if needed). */
  private async validPair(): Promise<TokenPair> {
    if (this.refreshing) return this.refreshing;
    const pair = await this.currentPair();
    if (!pair) {
      throw await this.fail(new ApiError({ status: 401, code: 'unauthenticated' }), '');
    }
    if (pair.accessExpiresAt - EXPIRY_SKEW_MS <= this.opts.now()) {
      return this.refresh();
    }
    return pair;
  }

  /** After a token_expired for `usedToken`: join the running refresh, reuse a newer pair, or refresh. */
  private async pairAfterExpiry(usedToken: string): Promise<TokenPair> {
    if (this.refreshing) return this.refreshing;
    const pair = await this.currentPair();
    if (!pair) throw await this.fail(new ApiError({ status: 401, code: 'unauthenticated' }), '');
    if (pair.accessToken !== usedToken) return pair; // someone refreshed while this request was out
    return this.refresh();
  }

  /** Single flight: every caller during a refresh gets the same promise. */
  private refresh(): Promise<TokenPair> {
    if (!this.refreshing) {
      const run = this.doRefresh();
      this.refreshing = run;
      run.then(
        () => {
          if (this.refreshing === run) this.refreshing = null;
        },
        () => {
          if (this.refreshing === run) this.refreshing = null;
        },
      );
    }
    return this.refreshing;
  }

  private async doRefresh(): Promise<TokenPair> {
    const gen = this.generation;
    const pair = await this.currentPair();
    if (!pair) throw await this.fail(new ApiError({ status: 401, code: 'unauthenticated' }), '');

    const res = await this.fetchOnce('POST', '/auth/refresh', { body: { refresh_token: pair.refreshToken } }, null);
    if (!res.ok) {
      const err = await errorFromResponse(res, this.opts.now());
      if (res.status === 401) {
        // invalid_token / session_ended (or anything else): this sign-in cannot be renewed.
        throw await this.fail(err.endsSession ? err : new ApiError({ status: 401, code: 'session_ended', message: err.message, requestId: err.requestId }), '/auth/refresh');
      }
      throw await this.fail(err, '/auth/refresh');
    }
    const fresh = pairFromResponse((await res.json()) as TokenResponse, this.opts.now());
    if (gen !== this.generation) {
      // Signed out (or in as someone else) while refreshing: drop the result.
      throw new ApiError({ status: 401, code: 'unauthenticated' });
    }
    await this.opts.tokens.save(fresh); // stored before anyone uses it
    this.pair = fresh;
    return fresh;
  }

  /* ------------------------------------------------------------------- errors */

  /** Side effects of an error answer; returns the error to throw. */
  private async fail(err: ApiError, path: string): Promise<ApiError> {
    if (err.endsSession) {
      await this.clearSession();
      this.opts.onSessionEnded?.(err);
    } else if (err.status === 429 && err.code === 'rate_limited') {
      this.blockedUntil = this.opts.now() + (err.retryAfter ?? 60) * 1000;
    } else if (err.status === 503 && err.code === 'module_unavailable') {
      const module = /^\/([a-z_-]+)\//.exec(path)?.[1];
      if (module) this.opts.onModuleUnavailable?.(module);
    }
    return err;
  }

  private async waitForRateWindow(): Promise<void> {
    const wait = this.blockedUntil - this.opts.now();
    if (wait <= 0) return;
    if (wait > this.opts.maxRateWait) {
      throw new ApiError({ status: 429, code: 'rate_limited', retryAfter: Math.ceil(wait / 1000) });
    }
    await this.opts.sleep(wait);
  }
}

function isFormData(v: unknown): v is FormData {
  return typeof FormData !== 'undefined' && v instanceof FormData;
}

function stripQuery(path: string): string {
  return path.split('?')[0] ?? path;
}

export function filenameFromDisposition(header: string | null): string | null {
  if (!header) return null;
  const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header);
  if (!m) return null;
  const name = decodeURIComponent(m[1] ?? '').replace(/[^A-Za-z0-9._-]/g, '_');
  return name || null;
}
