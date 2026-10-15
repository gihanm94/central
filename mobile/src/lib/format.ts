import type { Decimal } from '@/api/types';
import type { Language } from '@/i18n';

/**
 * PostgreSQL timestamps ("2026-10-15 10:00:00+07", "2026-10-15 15:32:20.74816+07") and dates
 * ("2026-10-15") → Date. Hermes' Date parser wants ISO 8601, so the text is normalised first.
 */
export function parseDate(value: string | null | undefined): Date | null {
  if (!value) return null;
  let s = value.trim();
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(s);
  if (dateOnly) {
    const [y, m, d] = s.split('-').map(Number) as [number, number, number];
    return new Date(y, m - 1, d);
  }
  s = s.replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1');
  s = s.replace(/([+-]\d{2})$/, '$1:00').replace(/([+-]\d{2})(\d{2})$/, '$1:$2');
  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? null : d;
}

const locales: Record<Language, string> = { en: 'en-GB', th: 'th-TH-u-ca-gregory' };

export function formatDate(value: string | null | undefined, lang: Language): string {
  const d = parseDate(value);
  if (!d) return '—';
  return d.toLocaleDateString(locales[lang], { day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatDateTime(value: string | null | undefined, lang: Language): string {
  const d = parseDate(value);
  if (!d) return '—';
  if (value && /^\d{4}-\d{2}-\d{2}$/.test(value.trim())) return formatDate(value, lang);
  return `${d.toLocaleDateString(locales[lang], { day: 'numeric', month: 'short', year: 'numeric' })} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function toNumber(v: Decimal | null | undefined): number | null {
  if (v === null || v === undefined || v === '') return null;
  const n = typeof v === 'number' ? v : Number(v);
  return Number.isFinite(n) ? n : null;
}

export function formatNumber(v: Decimal | null | undefined, lang: Language, digits = 0): string {
  const n = toNumber(v);
  if (n === null) return '—';
  return n.toLocaleString(locales[lang], { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

export function formatMoney(v: Decimal | null | undefined, currency: string | null | undefined, lang: Language): string {
  const n = toNumber(v);
  if (n === null) return '—';
  const amount = n.toLocaleString(locales[lang], { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return currency ? `${amount} ${currency}` : amount;
}

export function pad(n: number): string {
  return String(n).padStart(2, '0');
}

/** Date → "YYYY-MM-DD HH:mm" in local time (what the API takes for start_at). */
export function toApiDateTime(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Date → "YYYY-MM-DD" in local time. */
export function toApiDate(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Plain text a person typed → the small HTML the website's editor would make (the server cleans HTML). */
export function textToHtml(text: string): string {
  const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return text
    .trim()
    .split(/\n{2,}/)
    .filter(Boolean)
    .map((p) => `<p>${esc(p).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

/** Stored HTML → readable plain text (for showing and for editing). */
export function htmlToText(html: string | null | undefined): string {
  if (!html) return '';
  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|li|h\d)>/gi, '\n\n')
    .replace(/<li[^>]*>/gi, '• ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}
