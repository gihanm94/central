import { ApiClient } from '../client';
import { ApiError } from '../errors';
import { MemoryTokenStore, type TokenPair } from '../tokens';
import { FakeServer, json } from '@/testing/fakeServer';

const BASE = 'https://erp.example.com/api/v1';

function setup(pair: Partial<TokenPair> = {}, now = () => Date.now()) {
  const server = new FakeServer();
  const tokens = new MemoryTokenStore({
    accessToken: server.access,
    refreshToken: server.refresh,
    accessExpiresAt: now() + 900_000,
    refreshExpiresAt: now() + 86_400_000,
    deviceId: 7,
    ...pair,
  });
  const ended: ApiError[] = [];
  const unavailable: string[] = [];
  const client = new ApiClient({
    baseUrl: () => BASE,
    tokens,
    fetch: server.fetch,
    language: () => 'th',
    onSessionEnded: (e) => ended.push(e),
    onModuleUnavailable: (m) => unavailable.push(m),
    now,
  });
  return { server, tokens, client, ended, unavailable };
}

describe('ApiClient – tokens', () => {
  it('sends the bearer token and Accept-Language', async () => {
    const { client, server } = setup();
    await client.get('/me');
    expect(server.calls[0]!.auth).toBe('acme_at_A1');
    expect(server.calls[0]!.headers['Accept-Language']).toBe('th');
  });

  it('on 401 token_expired refreshes once and retries the request once', async () => {
    const { client, server, tokens } = setup();
    server.expireAccess();
    const res = await client.get<{ data: { path: string } }>('/crm/leads', { q: 'abc' });
    expect(res.data.path).toBe('/crm/leads?q=abc');
    expect(server.refreshCount).toBe(1);
    expect(server.calls.map((c) => c.path)).toEqual(['/crm/leads?q=abc', '/auth/refresh', '/crm/leads?q=abc']);
    // the new pair is stored before it is used
    expect(tokens.pair?.accessToken).toBe('acme_at_A2');
    expect(tokens.pair?.refreshToken).toBe('acme_rt_R2');
  });

  it('lets only ONE refresh run when many requests expire together', async () => {
    const { client, server, ended } = setup();
    server.expireAccess();
    const results = await Promise.all(
      Array.from({ length: 8 }, (_, i) => client.get<{ data: { path: string } }>(`/crm/leads/${i}`)),
    );
    expect(results.map((r) => r.data.path)).toEqual(Array.from({ length: 8 }, (_, i) => `/crm/leads/${i}`));
    expect(server.refreshCount).toBe(1);
    expect(server.revoked).toBe(false);
    expect(ended).toHaveLength(0);
  });

  it('queues requests that start while a refresh is running', async () => {
    const { client, server } = setup();
    server.expireAccess();
    server.refreshDelay = 50;
    const first = client.get('/a');
    await new Promise((r) => setTimeout(r, 10)); // refresh is now in flight
    const later = client.get('/b');
    await Promise.all([first, later]);
    expect(server.refreshCount).toBe(1);
    const b = server.calls.filter((c) => c.path === '/b');
    expect(b).toHaveLength(1); // waited for the new token instead of failing first
    expect(b[0]!.auth).toBe('acme_at_A2');
  });

  it('refreshes before sending when the stored access token has already run out', async () => {
    const { client, server } = setup({ accessExpiresAt: Date.now() - 1000 });
    await Promise.all([client.get('/x'), client.get('/y'), client.get('/z')]);
    expect(server.refreshCount).toBe(1);
    expect(server.calls.filter((c) => c.path !== '/auth/refresh').every((c) => c.auth === 'acme_at_A2')).toBe(true);
  });

  it('does not refresh again for a request that was sent with a token that has since been replaced', async () => {
    const { client, server } = setup();
    // /slow is answered after the other request has already refreshed the pair
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const slowFetch = server.fetch;
    server.fetch = async (input, init) => {
      if (String(input).endsWith('/slow') && (init?.headers as Record<string, string>).Authorization === 'Bearer acme_at_A1') {
        await gate;
        return json(401, { error: { code: 'token_expired', message: '' } });
      }
      return slowFetch(input, init);
    };
    const c = new ApiClient({ baseUrl: () => BASE, tokens: new MemoryTokenStore((await (client as unknown as { currentPair: () => Promise<TokenPair> }).currentPair())), fetch: server.fetch });
    const slow = c.get('/slow');
    server.expireAccess();
    await c.get('/fast'); // refresh #1 → A2
    release();
    await slow; // retried with A2, no second refresh
    expect(server.refreshCount).toBe(1);
    expect(server.calls.filter((x) => x.path === '/slow').map((x) => x.auth)).toEqual(['acme_at_A2']);
  });

  it.each(['invalid_token', 'session_ended', 'no_api_access', 'unauthenticated'])('clears the tokens on 401 %s and reports the session end', async (code) => {
    const { client, server, tokens, ended } = setup();
    server.handlers.push(() => ({ status: 401, body: { error: { code, message: 'Sign in again.' } } }));
    await expect(client.get('/me')).rejects.toMatchObject({ status: 401, code });
    expect(tokens.pair).toBeNull();
    expect(ended.map((e) => e.code)).toEqual([code]);
    expect(server.refreshCount).toBe(0);
  });

  it('ends the session when the refresh itself is refused', async () => {
    const { client, server, tokens, ended } = setup();
    server.expireAccess();
    server.revoked = true;
    await expect(client.get('/me')).rejects.toMatchObject({ status: 401, code: 'invalid_token' });
    expect(tokens.pair).toBeNull();
    expect(ended).toHaveLength(1);
  });

  it('ends the session when the request is still "expired" right after a refresh', async () => {
    const { client, server, tokens, ended } = setup();
    server.handlers.push((c) => (c.path === '/me' ? { status: 401, body: { error: { code: 'token_expired', message: 'x' } } } : undefined));
    await expect(client.get('/me')).rejects.toMatchObject({ code: 'session_ended' });
    expect(server.refreshCount).toBe(1);
    expect(tokens.pair).toBeNull();
    expect(ended).toHaveLength(1);
  });

  it('without a stored sign-in fails as unauthenticated without calling the server', async () => {
    const server = new FakeServer();
    const ended: string[] = [];
    const client = new ApiClient({ baseUrl: () => BASE, tokens: new MemoryTokenStore(null), fetch: server.fetch, onSessionEnded: (e) => ended.push(e.code) });
    await expect(client.get('/me')).rejects.toMatchObject({ code: 'unauthenticated' });
    expect(server.calls).toHaveLength(0);
    expect(ended).toEqual(['unauthenticated']);
  });

  it('a refresh that finishes after sign-out does not bring the tokens back', async () => {
    const { client, server, tokens } = setup();
    server.expireAccess();
    server.refreshDelay = 40;
    const req = client.get('/me').catch((e: unknown) => e);
    await new Promise((r) => setTimeout(r, 10));
    await client.clearSession();
    const err = await req;
    expect(err).toBeInstanceOf(ApiError);
    expect(tokens.pair).toBeNull();
  });

  it('login and refresh go out without Authorization', async () => {
    const { client, server } = setup();
    server.handlers.push((c) => (c.path === '/auth/login' ? { status: 200, body: { ok: true } } : undefined));
    await client.post('/auth/login', { email: 'a@b.c' }, { auth: false });
    expect(server.calls[0]!.auth).toBeNull();
  });
});

describe('ApiClient – errors', () => {
  it('maps the error envelope to ApiError with fields and request id', async () => {
    const { client, server } = setup();
    server.handlers.push(() => ({ status: 422, body: { error: { code: 'invalid', message: 'Status is required.', fields: { status: 'Status is required.', lead_id: 'Lead is required.' } } } }));
    const err = (await client.post('/crm/activities', {}).catch((e: unknown) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.isValidation).toBe(true);
    expect(err.message).toBe('Status is required.');
    expect(err.fields).toEqual({ status: 'Status is required.', lead_id: 'Lead is required.' });
    expect(err.requestId).toBe('req123');
  });

  it('keeps 404 pdf_not_available with the server message', async () => {
    const { client, server } = setup();
    server.handlers.push(() => ({ status: 404, body: { error: { code: 'pdf_not_available', message: 'This document has not been generated yet.' } } }));
    await expect(client.download('/accounting/inets/1/pdf/388')).rejects.toMatchObject({ status: 404, code: 'pdf_not_available', message: 'This document has not been generated yet.' });
  });

  it('reports 503 module_unavailable for that module only', async () => {
    const { client, server, unavailable, ended } = setup();
    server.handlers.push((c) => (c.path.startsWith('/accounting') ? { status: 503, body: { error: { code: 'module_unavailable', message: 'Temporarily unavailable.' } } } : undefined));
    await expect(client.get('/accounting/stats')).rejects.toMatchObject({ status: 503, code: 'module_unavailable' });
    await expect(client.get('/crm/stats')).resolves.toBeTruthy();
    expect(unavailable).toEqual(['accounting']);
    expect(ended).toHaveLength(0);
  });

  it('respects 429 Retry-After: fails fast while the window is long, waits when it is short', async () => {
    let t = 1_000_000;
    const sleeps: number[] = [];
    const server = new FakeServer();
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore({ accessToken: server.access, refreshToken: server.refresh, accessExpiresAt: t + 900_000, refreshExpiresAt: t + 9e9, deviceId: 1 }),
      fetch: server.fetch,
      now: () => t,
      sleep: async (ms) => {
        sleeps.push(ms);
        t += ms;
      },
    });
    server.handlers.push((c) => (c.path === '/busy' ? { status: 429, body: { error: { code: 'rate_limited', message: 'Too many requests. Try again in 30 seconds.' } }, headers: { 'Retry-After': '30' } } : undefined));
    await expect(client.get('/busy')).rejects.toMatchObject({ status: 429, code: 'rate_limited', retryAfter: 30 });
    const callsBefore = server.calls.length;
    await expect(client.get('/other')).rejects.toMatchObject({ code: 'rate_limited' }); // not sent at all
    expect(server.calls.length).toBe(callsBefore);
    t += 27_000; // 3 s left: the client waits instead of failing
    await expect(client.get('/other')).resolves.toBeTruthy();
    expect(sleeps).toEqual([3000]);
  });

  it('does not block other requests after a login lockout (429 locked)', async () => {
    const { client, server } = setup();
    server.handlers.push((c) => (c.path === '/auth/login' ? { status: 429, body: { error: { code: 'locked', message: 'Locked.' } }, headers: { 'Retry-After': '600' } } : undefined));
    await expect(client.post('/auth/login', {}, { auth: false })).rejects.toMatchObject({ code: 'locked', retryAfter: 600 });
    await expect(client.get('/me')).resolves.toBeTruthy();
  });

  it('turns a network failure into code "network"', async () => {
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore(null),
      fetch: async () => {
        throw new TypeError('Network request failed');
      },
    });
    await expect(client.post('/auth/login', {}, { auth: false })).rejects.toMatchObject({ status: 0, code: 'network' });
  });

  it('maps a non-JSON error page to a generic code', async () => {
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore(null),
      fetch: async () => new Response('<html>Bad gateway</html>', { status: 502 }),
    });
    await expect(client.post('/auth/login', {}, { auth: false })).rejects.toMatchObject({ status: 502, code: 'server_error' });
  });

  it('never logs bodies, tokens or query strings', async () => {
    const lines: string[] = [];
    const server = new FakeServer();
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore({ accessToken: server.access, refreshToken: server.refresh, accessExpiresAt: Date.now() + 9e5, refreshExpiresAt: Date.now() + 9e9, deviceId: 1 }),
      fetch: server.fetch,
      log: (l) => lines.push(l),
    });
    server.expireAccess();
    await client.get('/crm/search', { q: 'somchai@example.com' });
    const all = lines.join('\n');
    expect(all).not.toMatch(/acme_(at|rt)_/);
    expect(all).not.toContain('somchai');
    expect(lines[0]).toBe('GET /crm/search → 401 req123');
  });
});

describe('ApiClient – url', () => {
  it('drops empty and false query values and turns true into 1', () => {
    const { client } = setup();
    expect(client.url('/crm/leads', { q: '', mine: true, open: false, page: 2, lead_id: undefined })).toBe(`${BASE}/crm/leads?mine=1&page=2`);
  });

  it('downloads a file with its name', async () => {
    const pdf = new Uint8Array([37, 80, 68, 70]);
    const c = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore({ accessToken: 'a', refreshToken: 'r', accessExpiresAt: Date.now() + 9e5, refreshExpiresAt: Date.now() + 9e9, deviceId: 1 }),
      fetch: async () => new Response(pdf, { status: 200, headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="1001_388.pdf"' } }),
    });
    const d = await c.download('/accounting/inets/1/pdf/388');
    expect(d.filename).toBe('1001_388.pdf');
    expect(Array.from(d.bytes)).toEqual([37, 80, 68, 70]);
  });
});

describe('ApiClient – review fixes', () => {
  const pairOf = (access: string, refresh: string) => ({ accessToken: access, refreshToken: refresh, accessExpiresAt: Date.now() + 9e5, refreshExpiresAt: Date.now() + 9e9, deviceId: 1 });

  it('a late "session ended" for the previous person does not sign out the next one', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const tokens = new MemoryTokenStore(pairOf('acme_at_A', 'acme_rt_A'));
    const ended: string[] = [];
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens,
      onSessionEnded: (e) => ended.push(e.code),
      fetch: async (_url, init) => {
        const auth = (init?.headers as Record<string, string>).Authorization;
        if (auth === 'Bearer acme_at_A') {
          await gate;
          return json(401, { error: { code: 'invalid_token', message: 'Sign in again.' } });
        }
        return json(200, { data: { ok: true } });
      },
    });
    const old = client.get('/me').catch((e: unknown) => e);
    await new Promise((r) => setTimeout(r, 5));
    await client.clearSession();
    await client.startSession({ token_type: 'Bearer', access_token: 'acme_at_B', expires_in: 900, refresh_token: 'acme_rt_B', refresh_expires_in: 9999, device_id: 2 });
    release();
    expect(await old).toBeInstanceOf(ApiError);
    expect(tokens.pair?.accessToken).toBe('acme_at_B');
    expect(ended).toEqual([]);
    await expect(client.get('/me')).resolves.toBeTruthy();
  });

  it('never retries an old request with the next person\'s token', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const seen: string[] = [];
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore(pairOf('acme_at_A', 'acme_rt_A')),
      fetch: async (url, init) => {
        const auth = (init?.headers as Record<string, string>).Authorization ?? '';
        seen.push(`${String(url).replace(BASE, '')} ${auth}`);
        if (auth === 'Bearer acme_at_A') {
          await gate;
          return json(401, { error: { code: 'token_expired', message: '' } });
        }
        return json(200, { data: {} });
      },
    });
    const old = client.get('/crm/leads').catch((e: unknown) => e);
    await new Promise((r) => setTimeout(r, 5)); // the old request is out
    await client.clearSession();
    await client.startSession({ token_type: 'Bearer', access_token: 'acme_at_B', expires_in: 900, refresh_token: 'acme_rt_B', refresh_expires_in: 9999, device_id: 2 });
    release();
    await old;
    expect(seen).toEqual(['/crm/leads Bearer acme_at_A']);
  });

  it('keeps a refreshed pair when the keychain write fails, and writes it again later', async () => {
    const server = new FakeServer();
    const tokens = new MemoryTokenStore(pairOf(server.access, server.refresh));
    let failNext = false;
    const save = tokens.save.bind(tokens);
    tokens.save = async (p) => {
      if (failNext) {
        failNext = false;
        throw new Error('keychain locked');
      }
      return save(p);
    };
    const client = new ApiClient({ baseUrl: () => BASE, tokens, fetch: server.fetch });
    server.expireAccess();
    failNext = true;
    await client.get('/a'); // refresh → R2, write fails
    expect(tokens.pair?.refreshToken).toBe('acme_rt_R1');
    await client.get('/b'); // no second refresh; the pair is written now
    expect(server.refreshCount).toBe(1);
    expect(tokens.pair?.refreshToken).toBe('acme_rt_R2');
    expect(server.revoked).toBe(false);
  });

  it('gives up after the timeout', async () => {
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore(null),
      timeoutMs: 30,
      fetch: (_u, init) =>
        new Promise((_r, reject) => {
          init?.signal?.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
        }),
    });
    await expect(client.post('/auth/login', {}, { auth: false })).rejects.toMatchObject({ code: 'timeout' });
  });

  it('refuses an answer that was redirected to another host', async () => {
    const client = new ApiClient({
      baseUrl: () => BASE,
      tokens: new MemoryTokenStore(pairOf('acme_at_A', 'acme_rt_A')),
      fetch: async () => {
        const r = json(200, { data: {} });
        Object.defineProperty(r, 'url', { value: 'https://evil.example.net/me' });
        return r;
      },
    });
    await expect(client.get('/me')).rejects.toMatchObject({ code: 'bad_response' });
  });

  it('reports a module as available again after a normal answer', async () => {
    const back: string[] = [];
    const client = new ApiClient({ baseUrl: () => BASE, tokens: new MemoryTokenStore(pairOf('acme_at_A', 'acme_rt_A')), fetch: async () => json(200, { data: {} }), onModuleAvailable: (m) => back.push(m) });
    await client.get('/accounting/stats');
    await client.get('/me');
    expect(back).toEqual(['accounting']);
  });
});

