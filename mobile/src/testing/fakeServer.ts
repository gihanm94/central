/**
 * A tiny stand-in for /api/v1 that follows the server's token rules: refresh tokens rotate,
 * and using one twice signs the device out (like app/Core/Api/Tokens.php).
 */
export interface FakeCall {
  method: string;
  path: string;
  auth: string | null;
  body: unknown;
  headers: Record<string, string>;
}

type Handler = (call: FakeCall) => { status: number; body?: unknown; headers?: Record<string, string> } | undefined;

export class FakeServer {
  calls: FakeCall[] = [];
  access = 'acme_at_A1';
  refresh = 'acme_rt_R1';
  usedRefresh = new Set<string>();
  revoked = false;
  refreshCount = 0;
  /** Delay of /auth/refresh answers (ms), to let requests pile up behind it. */
  refreshDelay = 20;
  handlers: Handler[] = [];
  private n = 1;

  fetch = async (input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> => {
    const url = new URL(String(input));
    const headers = (init.headers ?? {}) as Record<string, string>;
    const auth = headers.Authorization?.replace(/^Bearer /, '') ?? null;
    let body: unknown = init.body;
    if (typeof init.body === 'string') body = JSON.parse(init.body);
    const call: FakeCall = { method: init.method ?? 'GET', path: url.pathname.replace('/api/v1', '') + url.search, auth, body, headers };
    this.calls.push(call);

    if (call.path === '/auth/refresh') {
      this.refreshCount++;
      await new Promise((r) => setTimeout(r, this.refreshDelay));
      const token = (body as { refresh_token: string }).refresh_token;
      if (this.revoked || this.usedRefresh.has(token)) {
        this.revoked = true; // reuse = stolen: device signed out
        return json(401, { error: { code: 'invalid_token', message: 'Sign in again.' } });
      }
      if (token !== this.refresh) return json(401, { error: { code: 'invalid_token', message: 'Sign in again.' } });
      this.usedRefresh.add(token);
      this.n++;
      this.access = `acme_at_A${this.n}`;
      this.refresh = `acme_rt_R${this.n}`;
      return json(200, { token_type: 'Bearer', access_token: this.access, expires_in: 900, refresh_token: this.refresh, refresh_expires_in: 2592000, device_id: 7 });
    }

    for (const h of this.handlers) {
      const r = h(call);
      if (r) return json(r.status, r.body, r.headers);
    }

    if (this.revoked) return json(401, { error: { code: 'token_expired', message: 'Expired.' } });
    if (auth !== this.access) return json(401, { error: { code: 'token_expired', message: 'The access token has expired. Refresh it.' } });
    return json(200, { data: { path: call.path, method: call.method } });
  };

  /** The server lets the current access token run out. */
  expireAccess(): void {
    this.access = `acme_at_expired_${this.n}`;
  }
}

export function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', 'X-Request-Id': 'req123', ...headers },
  });
}
