import { checkScannedText, looksLikeMachineCode } from '../qr';

describe('checkScannedText', () => {
  it('accepts JSON QR, links and plain codes as text for the server', () => {
    expect(checkScannedText('{"code":"OPS-CN01-0001"}')).toEqual({ ok: true, text: '{"code":"OPS-CN01-0001"}' });
    expect(checkScannedText(' https://erp.example.com/machines/m/OPS-1 ')).toEqual({ ok: true, text: 'https://erp.example.com/machines/m/OPS-1' });
    expect(checkScannedText('OPS-1')).toEqual({ ok: true, text: 'OPS-1' });
  });

  it('refuses empty, too long and binary content', () => {
    expect(checkScannedText('  ')).toEqual({ ok: false, reason: 'empty' });
    expect(checkScannedText(null)).toEqual({ ok: false, reason: 'empty' });
    expect(checkScannedText('x'.repeat(501))).toEqual({ ok: false, reason: 'too_long' });
    expect(checkScannedText('ab\u0000cd')).toEqual({ ok: false, reason: 'binary' });
  });

  it('allows exactly 500 characters', () => {
    expect(checkScannedText('x'.repeat(500)).ok).toBe(true);
  });
});

describe('looksLikeMachineCode', () => {
  it('matches the server rule for bare codes', () => {
    expect(looksLikeMachineCode('OPS-CN01-0001')).toBe(true);
    expect(looksLikeMachineCode('ab')).toBe(false);
    expect(looksLikeMachineCode('a b c')).toBe(false);
  });
});
