/**
 * Runs the app's main flows against a real backend with the app's own API client:
 *   sign in → /me → list leads → log a call → scan a machine → submit a check with a photo
 *   → download an Inet PDF → parallel requests with an expired token (one refresh only) → sign out.
 *
 *   npm run flows                       # http://127.0.0.1:8000, demo users
 *   API_URL=https://erp.example.com MEMBER_EMAIL=… MEMBER_PASSWORD=… npm run flows
 *
 * Environment (all optional):
 *   API_URL            server root (default http://127.0.0.1:8000)
 *   MEMBER_EMAIL       CRM + Machine Checklist user (default member@acmeinter.com)
 *   MEMBER_PASSWORD    (default Password@123)
 *   ACCOUNTING_EMAIL   user who may see Inets (default admin@acmeinter.com)
 *   ACCOUNTING_PASSWORD
 *   SCAN_TEXT          what the QR code says (default {"code":"OPS-CN01-0001"})
 *
 * Never prints tokens, passwords or response bodies.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { deflateSync } from 'node:zlib';

import { ApiClient } from '../src/api/client';
import { createApi, type Api } from '../src/api/endpoints';
import { ApiError } from '../src/api/errors';
import { MemoryTokenStore } from '../src/api/tokens';
import { apiBaseFromServerUrl } from '../src/lib/serverUrl';

const root = process.env.API_URL ?? 'http://127.0.0.1:8000';
const member = { email: process.env.MEMBER_EMAIL ?? 'member@acmeinter.com', password: process.env.MEMBER_PASSWORD ?? 'Password@123' };
const accountant = { email: process.env.ACCOUNTING_EMAIL ?? 'admin@acmeinter.com', password: process.env.ACCOUNTING_PASSWORD ?? 'Password@123' };
const scanText = process.env.SCAN_TEXT ?? '{"code":"OPS-CN01-0001"}';

const base = apiBaseFromServerUrl(root, { allowInsecureLocal: true });
if (!base.ok) {
  console.error(`API_URL: ${base.error}`);
  process.exit(2);
}

let failures = 0;
async function step<T>(name: string, fn: () => Promise<T>): Promise<T | undefined> {
  const started = Date.now();
  try {
    const out = await fn();
    console.log(`  ✓ ${name} (${Date.now() - started} ms)`);
    return out;
  } catch (e) {
    failures++;
    const err = e as ApiError;
    console.log(`  ✗ ${name}: ${err instanceof ApiError ? `${err.status} ${err.code} ${err.message}` : String(e)}${err.requestId ? ` [request ${err.requestId}]` : ''}`);
    return undefined;
  }
}

function assert(cond: unknown, msg: string): asserts cond {
  if (!cond) throw new Error(msg);
}

function session(): { api: Api; client: ApiClient; tokens: MemoryTokenStore; ended: string[]; refreshes: () => number } {
  const tokens = new MemoryTokenStore();
  const ended: string[] = [];
  let refreshes = 0;
  const client = new ApiClient({
    baseUrl: () => (base.ok ? base.url : ''),
    tokens,
    language: () => 'en',
    onSessionEnded: (e) => ended.push(e.code),
    fetch: (input, init) => {
      if (String(input).endsWith('/auth/refresh')) refreshes++;
      return fetch(input, init);
    },
  });
  return { api: createApi(client), client, tokens, ended, refreshes: () => refreshes };
}

async function signIn(s: ReturnType<typeof session>, who: { email: string; password: string }) {
  const res = await s.api.auth.login({ ...who, device_name: 'Flow script', device_id: `flows-${who.email.split('@')[0]}-script`, platform: 'other' });
  await s.client.startSession(res);
  return res.user;
}

/** A 2×2 PNG (valid image for the server's upload check). */
function png(): Uint8Array {
  const crcTable = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
  });
  const crc = (buf: Buffer) => {
    let c = 0xffffffff;
    for (const b of buf) c = crcTable[(c ^ b) & 0xff]! ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
  };
  const chunk = (type: string, data: Buffer) => {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const c = Buffer.alloc(4);
    c.writeUInt32BE(crc(td));
    return Buffer.concat([len, td, c]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(2, 0);
  ihdr.writeUInt32BE(2, 4);
  ihdr[8] = 8;
  ihdr[9] = 2;
  const raw = Buffer.from([0, 255, 0, 0, 0, 255, 0, 0, 0, 0, 255, 0, 0, 255, 0]);
  return new Uint8Array(Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]));
}

async function main() {
  console.log(`Flows against ${base.ok ? base.url : ''}\n`);

  /* ---------------------------------------------------------------- member */
  console.log(`As ${member.email}`);
  const m = session();
  const user = await step('sign in', () => signIn(m, member));
  if (!user) return;
  const me = await step('GET /me (modules + permissions)', async () => {
    const p = (await m.api.me.get()).data;
    assert(Array.isArray(p.modules), 'modules missing');
    return p;
  });
  console.log(`    modules: ${me?.modules.join(', ') || '—'}`);

  const leads = await step('list leads', async () => {
    const page = await m.api.crm.leads({ per_page: 5 });
    assert(page.meta.total >= 0 && Array.isArray(page.data), 'bad page');
    return page;
  });
  const lead = leads?.data[0];
  if (lead) {
    await step(`lead detail #${lead.id}`, () => m.api.crm.lead(lead.id));
    const call = await step('log a call', async () => {
      const contacts = await m.api.crm.contacts({ lead_id: lead.id, per_page: 1 });
      const a = await m.api.crm.createActivity({
        activity_type: 'CALL',
        topic: 'Follow-up call (flow script)',
        status: 'DONE',
        start_at: new Date().toISOString().slice(0, 16).replace('T', ' '),
        call_direction: 'OUTBOUND',
        call_duration: 5,
        lead_id: lead.id,
        contact_id: contacts.data[0]?.id ?? null,
        description: '<p>Logged by scripts/flows.ts</p>',
      });
      assert(a.data.id && a.data.lead_id === lead.id, 'activity not linked to the lead');
      return a.data;
    });
    if (call) {
      await step('edit the call (topic only)', async () => {
        const a = await m.api.crm.updateActivity(call.id, { topic: 'Follow-up call (flow script, edited)' });
        assert(a.data.topic.endsWith('edited)') && a.data.call_duration === 5, 'other fields changed');
      });
      await step('422 shows field errors', async () => {
        const e = await m.api.crm.createActivity({ activity_type: 'CALL', topic: 'x', status: 'DONE' } as never).catch((x: unknown) => x);
        assert(e instanceof ApiError && e.status === 422 && e.fields?.lead_id, 'expected 422 with fields.lead_id');
      });
    }
  } else {
    console.log('    (no visible leads: skipped lead detail and log a call)');
  }

  const machine = await step('scan a QR code', async () => (await m.api.machines.scan(scanText)).data);
  await step('unknown QR → 404 machine_not_found', async () => {
    const e = await m.api.machines.scan('NO-SUCH-MACHINE').catch((x: unknown) => x);
    assert(e instanceof ApiError && e.code === 'machine_not_found', 'expected machine_not_found');
  });
  if (machine?.checklist?.can_check) {
    await step(`submit a check with a photo on ${machine.machine.machine_code} (multipart)`, async () => {
      const answers: Record<string, { answer: string; remark?: string }> = {};
      for (const item of machine.checklist!.items) {
        answers[String(item.id)] = item.answer_type === 'choice' ? { answer: 'OK' } : { answer: '42' };
      }
      const first = machine.checklist!.items.find((i) => i.answer_type === 'choice');
      if (first) answers[String(first.id)] = { answer: 'NG', remark: 'Flow script: remark for NG' };
      const rec = await m.api.machines.submitCheck(
        machine.machine.machine_code,
        { machine_status: machine.checklist!.machine_statuses[0] ?? 'OPERATIONAL', answers, machine_note: 'Checked by scripts/flows.ts' },
        { blob: new Blob([png() as BlobPart], { type: 'image/png' }), name: 'check.png' },
      );
      assert(rec.data.id && rec.data.image_url, 'no record or photo');
      console.log(`    record #${rec.data.id}: ${rec.data.checklist_status}`);
    });
  } else if (machine) {
    console.log(`    (no check to do on ${machine.machine.machine_code} for this person: skipped)`);
  }
  await step('my checks', () => m.api.machines.checklists({ per_page: 5 }));

  await step('only one refresh for parallel requests with an expired token', async () => {
    const before = m.refreshes();
    // Pretend the access token ran out: the server answers token_expired for an unknown token.
    const p = m.tokens.pair!;
    await m.tokens.save({ ...p, accessToken: `acme_at_${'x'.repeat(43)}` });
    (m.client as unknown as { pair: unknown }).pair = m.tokens.pair;
    const results = await Promise.all([m.api.me.get(), m.api.crm.stats(), m.api.machines.due(), m.api.me.devices(), m.api.crm.leads({ per_page: 1 })]);
    assert(results.length === 5, 'not all answered');
    assert(m.refreshes() - before === 1, `expected 1 refresh, saw ${m.refreshes() - before}`);
    assert(m.ended.length === 0, 'device was signed out');
  });

  await step('sign out', () => m.api.auth.logout());
  await step('old token no longer works', async () => {
    const e = await m.api.me.get().catch((x: unknown) => x);
    assert(e instanceof ApiError && e.status === 401, 'expected 401');
  });

  /* ------------------------------------------------------------ accounting */
  console.log(`\nAs ${accountant.email}`);
  const a = session();
  if (await step('sign in', () => signIn(a, accountant))) {
    await step('accounting overview', () => a.api.accounting.stats());
    const inets = await step('list Inets', () => a.api.accounting.inets({ per_page: 50 }));
    const doc = inets?.data.flatMap((i) => i.documents.filter((d) => d.pdf_url).map((d) => ({ inet: i, doc: d })))[0];
    if (doc) {
      await step(`download Inet ${doc.inet.no_invoice} ${doc.doc.label} PDF`, async () => {
        const file = await a.api.accounting.pdfFromUrl(doc.doc.pdf_url!);
        assert(new TextDecoder().decode(file.bytes.slice(0, 5)) === '%PDF-', 'not a PDF');
        const dir = join(tmpdir(), 'acme-flows');
        mkdirSync(dir, { recursive: true });
        const path = join(dir, file.filename ?? 'inet.pdf');
        writeFileSync(path, file.bytes);
        console.log(`    saved ${file.bytes.length} bytes to ${path}`);
      });
      await step('signed copy not there yet → 404 pdf_not_available with a message', async () => {
        const unsigned = doc.inet.documents.find((d) => !d.signed);
        if (!unsigned) return;
        const e = await a.api.accounting.inetPdf(doc.inet.id, unsigned.type, true).catch((x: unknown) => x);
        assert(e instanceof ApiError && e.code === 'pdf_not_available' && e.message, 'expected pdf_not_available');
      });
    } else {
      console.log('    (no Inet with a generated PDF: skipped the download)');
    }
    await step('sign out', () => a.api.auth.logout());
  }
}

main()
  .catch((e) => {
    failures++;
    console.error(e);
  })
  .finally(() => {
    console.log(failures ? `\n${failures} step(s) failed` : '\nAll flows passed');
    process.exit(failures ? 1 : 0);
  });
