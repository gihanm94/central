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
  /** A module answered normally again. */
  onModuleAvailable?: (module: string) => void;
  /** Per-request deadline (ms). */
  timeoutMs?: number;
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
  private readonly opts: Required<Omit<ClientOptions, 'onSessionEnded' | 'onModuleUnavailable' | 'onModuleAvailable' | 'log' | 'language'>> &
    Pick<ClientOptions, 'onSessionEnded' | 'onModuleUnavailable' | 'onModuleAvailable' | 'log' | 'language'>;
  private pair: TokenPair | null = null;
  private loaded = false;
  private refreshing: Promise<TokenPair> | null = null;
  /** Bumped on sign-in and sign-out, so a refresh that finishes late cannot bring old tokens back. */
  private generation = 0;
  /** After a 429 rate_limited: no request goes out before this time (ms since epoch). */
  private blockedUntil = 0;
  /** The latest pair is only in memory (a keychain write failed). */
  private unsaved = false;

  constructor(options: ClientOptions) {
    this.opts = {
      fetch: (...args) => fetch(...args),
      now: () => Date.now(),
      sleep: (ms) => new Promise((r) => setTimeout(r, ms)),
      maxRateWait: 5_000,
      timeoutMs: 30_000,
      ...options,
    };
  }

  /* ------------------------------------------------------------------ session */

  /** Store a fresh sign-in (from /auth/login). */
  async startSession(response: TokenResponse): Promise<TokenPair> {
    this.generation++;
    const pair = pairFromResponse(response, this.opts.now());
    this.pair = pair;
    this.loaded = true;
    await this.persist(pair);
    return pair;
  }

  /** Forget the tokens (sign-out, or the server ended the sign-in). */
  async clearSession(): Promise<void> {
    this.generation++;
    this.pair = null;
    this.loaded = true;
    this.refreshing = null;
    this.unsaved = false;
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
    // Everything this request learns belongs to the sign-in it started under (see stale()).
    const gen = this.generation;
    await this.waitForRateWindow();

    let token: string | null = null;
    if (auth) {
      token = (await this.validPair(gen)).accessToken;
    }
    let res = await this.fetchOnce(method, path, options, token);

    if (auth && res.status === 401) {
      const err = await errorFromResponse(res, this.opts.now());
      if (err.code !== 'token_expired') {
        throw await this.fail(err, path, gen);
      }
      // Refresh once (or take the pair another request just got) and retry once.
      const fresh = await this.pairAfterExpiry(token!, gen);
      res = await this.fetchOnce(method, path, options, fresh.accessToken);
      if (res.status === 401) {
        const again = await errorFromResponse(res, this.opts.now());
        // Still "expired" right after a refresh means the sign-in is gone (e.g. revoked meanwhile).
        throw await this.fail(again.code === 'token_expired' ? new ApiError({ status: 401, code: 'session_ended', message: again.message, requestId: again.requestId }) : again, path, gen);
      }
    }

    if (!res.ok) {
      throw await this.fail(await errorFromResponse(res, this.opts.now()), path, gen);
    }
    const module = moduleOf(path);
    if (module && !this.stale(gen)) this.opts.onModuleAvailable?.(module);
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

    // Every request has a deadline, so a stalled refresh can never block the app (or sign-out) forever.
    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, this.opts.timeoutMs);
    const onCallerAbort = () => controller.abort();
    options.signal?.addEventListener('abort', onCallerAbort);

    let res: Response;
    try {
      res = await this.opts.fetch(url, { method, headers, body, signal: controller.signal });
    } catch (e) {
      if (timedOut) {
        this.opts.log?.(`${method} ${stripQuery(path)} → timeout`);
        throw new ApiError({ status: 0, code: 'timeout' });
      }
      if ((e as { name?: string } | null)?.name === 'AbortError') throw e;
      this.opts.log?.(`${method} ${stripQuery(path)} → network error`);
      throw new ApiError({ status: 0, code: 'network' });
    } finally {
      clearTimeout(timer);
      options.signal?.removeEventListener('abort', onCallerAbort);
    }
    // A redirect to another host must never be trusted (it may have carried the token there).
    if (res.url && originOf(res.url) !== originOf(url)) {
      this.opts.log?.(`${method} ${stripQuery(path)} → redirected to another host`);
      throw new ApiError({ status: 0, code: 'bad_response' });
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

  /** The sign-in changed (sign-out, or a new sign-in) since generation `gen`. */
  private stale(gen: number): boolean {
    return gen !== this.generation;
  }

  private staleError(): ApiError {
    return new ApiError({ status: 401, code: 'unauthenticated' });
  }

  /** A pair whose access token has not run out yet (refreshing first if needed). */
  private async validPair(gen: number): Promise<TokenPair> {
    if (this.refreshing) return this.joinRefresh(gen);
    const pair = await this.currentPair();
    if (this.stale(gen)) throw this.staleError();
    if (!pair) {
      throw await this.fail(new ApiError({ status: 401, code: 'unauthenticated' }), '', gen);
    }
    if (pair.accessExpiresAt - EXPIRY_SKEW_MS <= this.opts.now()) {
      return this.joinRefresh(gen, true);
    }
    if (this.unsaved) await this.persist(pair);
    return pair;
  }

  /** After a token_expired for `usedToken`: join the running refresh, reuse a newer pair, or refresh. */
  private async pairAfterExpiry(usedToken: string, gen: number): Promise<TokenPair> {
    if (this.stale(gen)) throw this.staleError(); // never retry with someone else's token
    if (this.refreshing) return this.joinRefresh(gen);
    const pair = await this.currentPair();
    if (!pair) throw await this.fail(new ApiError({ status: 401, code: 'unauthenticated' }), '', gen);
    if (pair.accessToken !== usedToken) return pair; // someone refreshed while this request was out
    return this.joinRefresh(gen, true);
  }

  private async joinRefresh(gen: number, start = false): Promise<TokenPair> {
    const pair = await (start ? this.refresh() : (this.refreshing ?? this.refresh()));
    if (this.stale(gen)) throw this.staleError();
    return pair;
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
    if (!pair) throw await this.fail(new ApiError({ status: 401, code: 'unauthenticated' }), '', gen);

    const res = await this.fetchOnce('POST', '/auth/refresh', { body: { refresh_token: pair.refreshToken } }, null);
    if (!res.ok) {
      const err = await errorFromResponse(res, this.opts.now());
      if (res.status === 401) {
        // invalid_token / session_ended (or anything else): this sign-in cannot be renewed.
        throw await this.fail(err.endsSession ? err : new ApiError({ status: 401, code: 'session_ended', message: err.message, requestId: err.requestId }), '/auth/refresh', gen);
      }
      throw await this.fail(err, '/auth/refresh', gen);
    }
    const fresh = pairFromResponse((await res.json()) as TokenResponse, this.opts.now());
    if (this.stale(gen)) {
      // Signed out (or in as someone else) while refreshing: drop the result.
      throw this.staleError();
    }
    // The server has already rotated: the new pair is the only valid one, so keep it in memory
    // even if the keychain write fails (e.g. the phone locked meanwhile) and write it again later.
    this.pair = fresh;
    await this.persist(fresh);
    return fresh;
  }

  /** Writes the pair to the token store; on failure remembers to try again before the next request. */
  private async persist(pair: TokenPair): Promise<void> {
    try {
      await this.opts.tokens.save(pair);
      this.unsaved = false;
    } catch {
      this.unsaved = true;
      this.opts.log?.('token store write failed; will retry');
    }
  }

  /* ------------------------------------------------------------------- errors */

  /** Side effects of an error answer; returns the error to throw. Answers for an old sign-in change nothing. */
  private async fail(err: ApiError, path: string, gen: number): Promise<ApiError> {
    if (this.stale(gen)) return err.endsSession ? this.staleError() : err;
    if (err.endsSession) {
      await this.clearSession();
      this.opts.onSessionEnded?.(err);
    } else if (err.status === 429 && err.code === 'rate_limited') {
      this.blockedUntil = this.opts.now() + (err.retryAfter ?? 60) * 1000;
    } else if (err.status === 503 && err.code === 'module_unavailable') {
      const module = moduleOf(path);
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

function moduleOf(path: string): string | undefined {
  const m = /^\/(crm|machines|accounting)\//.exec(path)?.[1];
  return m;
}

function originOf(url: string): string {
  return (/^[a-z][a-z0-9+.-]*:\/\/[^/?#]*/i.exec(url)?.[0] ?? '').toLowerCase();
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
