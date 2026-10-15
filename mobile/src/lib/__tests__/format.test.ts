import { defaultDeviceName } from '../deviceName';
import { htmlToText, parseDate, textToHtml, toApiDateTime } from '../format';

describe('parseDate', () => {
  it('reads PostgreSQL timestamps', () => {
    expect(parseDate('2026-10-15 10:00:00+07')?.toISOString()).toBe('2026-10-15T03:00:00.000Z');
    expect(parseDate('2026-10-15 15:32:20.74816+07')?.toISOString()).toBe('2026-10-15T08:32:20.748Z');
    expect(parseDate('2026-10-15T10:00:00+0530')?.toISOString()).toBe('2026-10-15T04:30:00.000Z');
  });

  it('reads plain dates as local dates', () => {
    const d = parseDate('2026-10-15')!;
    expect([d.getFullYear(), d.getMonth(), d.getDate()]).toEqual([2026, 9, 15]);
  });

  it('returns null for junk', () => {
    expect(parseDate('nope')).toBeNull();
    expect(parseDate(null)).toBeNull();
  });
});

describe('text and HTML', () => {
  it('escapes typed text into paragraphs', () => {
    expect(textToHtml('Price < 5 & ok\nsecond line\n\nNew <b>para</b>')).toBe('<p>Price &lt; 5 &amp; ok<br>second line</p><p>New &lt;b&gt;para&lt;/b&gt;</p>');
  });

  it('turns stored HTML back into text', () => {
    expect(htmlToText('<p>Asked for a <strong>revised</strong> price</p><p>a &amp; b<br>c</p>')).toBe('Asked for a revised price\n\na & b\nc');
    expect(htmlToText(textToHtml('x < y\n\nz'))).toBe('x < y\n\nz');
  });

  it('formats a date for the API', () => {
    expect(toApiDateTime(new Date(2026, 9, 5, 9, 7))).toBe('2026-10-05 09:07');
  });
});

describe('defaultDeviceName', () => {
  it("makes \"<name>'s <model>\"", () => {
    expect(defaultDeviceName('somchai.w@acme.com', 'iPhone 15')).toBe("Somchai's iPhone 15");
    expect(defaultDeviceName('', 'Pixel 8')).toBe('Pixel 8');
    expect(defaultDeviceName('123@x.com', null)).toBe('phone');
  });
});
