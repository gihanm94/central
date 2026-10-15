import { ApiClient } from '../client';
import { checkFormData, createApi, pdfPath } from '../endpoints';
import { MemoryTokenStore } from '../tokens';

function capture() {
  const calls: { url: string; init: RequestInit }[] = [];
  const client = new ApiClient({
    baseUrl: () => 'https://erp.example.com/api/v1',
    tokens: new MemoryTokenStore({ accessToken: 'acme_at_x', refreshToken: 'acme_rt_x', accessExpiresAt: Date.now() + 9e5, refreshExpiresAt: Date.now() + 9e9, deviceId: 3 }),
    fetch: async (url, init) => {
      calls.push({ url: String(url), init: init ?? {} });
      return new Response(JSON.stringify({ data: { id: 1 } }), { status: 201, headers: { 'Content-Type': 'application/json' } });
    },
  });
  return { api: createApi(client), calls };
}

const input = {
  machine_status: 'OPERATIONAL',
  answers: { '12': { answer: 'OK' }, '13': { answer: '42 mm' }, '14': { answer: 'NG', remark: 'Belt worn' } },
  machine_note: 'Noisy',
};

describe('machines.submitCheck', () => {
  it('sends JSON without a photo', async () => {
    const { api, calls } = capture();
    await api.machines.submitCheck('OPS-01', input);
    expect(calls[0]!.url).toBe('https://erp.example.com/api/v1/machines/m/OPS-01/check');
    expect((calls[0]!.init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    expect(JSON.parse(calls[0]!.init.body as string)).toEqual(input);
  });

  it('sends multipart/form-data with the same fields plus "image" when there is a photo', async () => {
    const { api, calls } = capture();
    await api.machines.submitCheck('OPS 01/x', input, { blob: new Blob([new Uint8Array([1, 2, 3])], { type: 'image/jpeg' }), name: 'check.jpg' });
    const { url, init } = calls[0]!;
    expect(url).toBe('https://erp.example.com/api/v1/machines/m/OPS%2001%2Fx/check');
    expect(init.body).toBeInstanceOf(FormData);
    // the boundary must come from fetch, not from us
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined();
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer acme_at_x');
    const form = init.body as FormData;
    expect(form.get('machine_status')).toBe('OPERATIONAL');
    expect(form.get('machine_note')).toBe('Noisy');
    expect(form.get('answers[12][answer]')).toBe('OK');
    expect(form.get('answers[13][answer]')).toBe('42 mm');
    expect(form.get('answers[14][answer]')).toBe('NG');
    expect(form.get('answers[14][remark]')).toBe('Belt worn');
    expect(form.get('answers[12][remark]')).toBeNull();
    const image = form.get('image') as File;
    expect(image.name).toBe('check.jpg');
    expect(image.size).toBe(3);
  });

  it('appends a React Native file reference as is', () => {
    const appended: [string, unknown][] = [];
    const orig = FormData.prototype.append;
    FormData.prototype.append = function (k: string, v: unknown) {
      appended.push([k, v]);
    } as typeof FormData.prototype.append;
    try {
      checkFormData(input, { uri: 'file:///cache/p.jpg', name: 'p.jpg', type: 'image/jpeg' });
    } finally {
      FormData.prototype.append = orig;
    }
    expect(appended.find(([k]) => k === 'image')?.[1]).toEqual({ uri: 'file:///cache/p.jpg', name: 'p.jpg', type: 'image/jpeg' });
  });
});

describe('pdfPath', () => {
  it('keeps only the path of known PDF urls, whatever the host', () => {
    expect(pdfPath('http://127.0.0.1:8000/api/v1/accounting/inets/1/pdf/388')).toEqual({ path: '/accounting/inets/1/pdf/388', query: undefined });
    expect(pdfPath('https://erp.example.com/api/v1/accounting/inets/9/pdf/t01?signed=1')).toEqual({ path: '/accounting/inets/9/pdf/t01', query: { signed: 1 } });
    expect(pdfPath('https://erp.example.com/api/v1/accounting/billing-notes/4/pdf')).toEqual({ path: '/accounting/billing-notes/4/pdf', query: undefined });
  });

  it('refuses anything else', () => {
    expect(pdfPath('https://evil.example.com/steal')).toBeNull();
    expect(pdfPath('https://erp.example.com/api/v1/auth/logout')).toBeNull();
    expect(pdfPath('https://erp.example.com/api/v1/accounting/inets/1/pdf/388/../../../me')).toBeNull();
  });
});

describe('endpoints', () => {
  it('builds list queries', async () => {
    const { api, calls } = capture();
    await api.crm.opportunities({ stage: 'NEGOTIATION', open: true, mine: true, page: 2 });
    await api.machines.checklists({ pending: true });
    await api.accounting.inets({ credit: true, send: 'unsent', q: 'ACME & co' });
    expect(calls.map((c) => c.url.replace('https://erp.example.com/api/v1', ''))).toEqual([
      '/crm/opportunities?stage=NEGOTIATION&open=1&mine=1&page=2',
      '/machines/checklists?pending=1',
      '/accounting/inets?credit=1&send=unsent&q=ACME%20%26%20co',
    ]);
  });
});
