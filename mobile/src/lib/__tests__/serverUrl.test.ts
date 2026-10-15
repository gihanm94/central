import { apiBaseFromServerUrl } from '../serverUrl';

const prod = { allowInsecureLocal: false };
const dev = { allowInsecureLocal: true };

describe('apiBaseFromServerUrl', () => {
  it('adds https:// and /api/v1', () => {
    expect(apiBaseFromServerUrl('erp.example.com', prod)).toEqual({ ok: true, url: 'https://erp.example.com/api/v1', origin: 'https://erp.example.com' });
    expect(apiBaseFromServerUrl('https://erp.example.com/', prod)).toMatchObject({ url: 'https://erp.example.com/api/v1' });
    expect(apiBaseFromServerUrl('https://erp.example.com/api/v1/', prod)).toMatchObject({ url: 'https://erp.example.com/api/v1', origin: 'https://erp.example.com' });
    expect(apiBaseFromServerUrl('https://Example.com:8443/erp', prod)).toMatchObject({ url: 'https://example.com:8443/erp/api/v1' });
  });

  it('refuses http in production builds, even for localhost', () => {
    expect(apiBaseFromServerUrl('http://erp.example.com', prod)).toEqual({ ok: false, error: 'https_required' });
    expect(apiBaseFromServerUrl('http://localhost:8000', prod)).toEqual({ ok: false, error: 'https_required' });
  });

  it('allows http only for the local machine in development builds', () => {
    expect(apiBaseFromServerUrl('http://localhost:8000', dev)).toMatchObject({ ok: true, url: 'http://localhost:8000/api/v1' });
    expect(apiBaseFromServerUrl('http://127.0.0.1:8000', dev)).toMatchObject({ ok: true });
    expect(apiBaseFromServerUrl('http://10.0.2.2:8000', dev)).toMatchObject({ ok: true });
    expect(apiBaseFromServerUrl('http://192.168.1.10:8000', dev)).toEqual({ ok: false, error: 'https_required' });
    expect(apiBaseFromServerUrl('http://localhost.evil.com', dev)).toEqual({ ok: false, error: 'https_required' });
  });

  it('refuses odd input', () => {
    expect(apiBaseFromServerUrl('', prod)).toEqual({ ok: false, error: 'empty' });
    expect(apiBaseFromServerUrl('ftp://x.com', prod)).toEqual({ ok: false, error: 'invalid' });
    expect(apiBaseFromServerUrl('https://user:pw@x.com', prod)).toEqual({ ok: false, error: 'invalid' });
    expect(apiBaseFromServerUrl('https://x.com/?a=1', prod)).toEqual({ ok: false, error: 'invalid' });
    expect(apiBaseFromServerUrl('https://x.com/a/../b', prod)).toEqual({ ok: false, error: 'invalid' });
    expect(apiBaseFromServerUrl('https://x..com', prod)).toEqual({ ok: false, error: 'invalid' });
    expect(apiBaseFromServerUrl('javascript:alert(1)', prod).ok).toBe(false);
  });
});
