/**
 * Errors from the API, as documented in docs/API.md:
 * {"error": {"code": "...", "message": "...", "fields": {...}}}.
 * `message` is already in the person's language and ready to show.
 */

/** 401 codes after which the app must forget the tokens and go to sign-in. */
export const SESSION_END_CODES = ['invalid_token', 'session_ended', 'no_api_access', 'unauthenticated'] as const;

/** Codes the client itself produces (no server answer). */
export type ClientErrorCode = 'network' | 'bad_response' | 'insecure_url' | 'no_server' | 'timeout';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields?: Record<string, string>;
  /** Seconds to wait (429 rate_limited / locked). */
  readonly retryAfter?: number;
  /** X-Request-Id of the answer, to quote when reporting a problem. */
  readonly requestId?: string;

  constructor(init: {
    status: number;
    code: string;
    message?: string;
    fields?: Record<string, string>;
    retryAfter?: number;
    requestId?: string;
  }) {
    super(init.message ?? '');
    this.name = 'ApiError';
    this.status = init.status;
    this.code = init.code;
    this.fields = init.fields;
    this.retryAfter = init.retryAfter;
    this.requestId = init.requestId;
  }

  /** The person must sign in again. */
  get endsSession(): boolean {
    return this.status === 401 && (SESSION_END_CODES as readonly string[]).includes(this.code);
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }

  get isValidation(): boolean {
    return this.status === 422 && this.code === 'invalid';
  }

  get isModuleUnavailable(): boolean {
    return this.status === 503;
  }

  get isRateLimited(): boolean {
    return this.status === 429;
  }
}

export function isApiError(e: unknown): e is ApiError {
  return e instanceof ApiError;
}

/** Retry-After header → whole seconds (also accepts an HTTP date). */
export function parseRetryAfter(value: string | null, now: number = Date.now()): number | undefined {
  if (!value) return undefined;
  const trimmed = value.trim();
  if (/^\d+$/.test(trimmed)) return Number(trimmed);
  const at = Date.parse(trimmed);
  return Number.isNaN(at) ? undefined : Math.max(0, Math.ceil((at - now) / 1000));
}

/**
 * Turns a non-2xx answer into an ApiError. Never puts the body into the message unless it is the
 * server's own error message (bodies can hold personal data).
 */
export async function errorFromResponse(res: Response, now: number = Date.now()): Promise<ApiError> {
  const requestId = res.headers.get('X-Request-Id') ?? undefined;
  const retryAfter = parseRetryAfter(res.headers.get('Retry-After'), now);
  let payload: unknown = null;
  try {
    payload = await res.json();
  } catch {
    payload = null;
  }
  const err = (payload as { error?: { code?: unknown; message?: unknown; fields?: unknown } } | null)?.error;
  if (err && typeof err.code === 'string') {
    const fields =
      err.fields && typeof err.fields === 'object' && !Array.isArray(err.fields)
        ? Object.fromEntries(Object.entries(err.fields as Record<string, unknown>).map(([k, v]) => [k, String(v)]))
        : undefined;
    return new ApiError({
      status: res.status,
      code: err.code,
      message: typeof err.message === 'string' ? err.message : undefined,
      fields,
      retryAfter,
      requestId,
    });
  }
  return new ApiError({ status: res.status, code: res.status >= 500 ? 'server_error' : 'http_error', retryAfter, requestId });
}
