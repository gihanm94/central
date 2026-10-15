import type { Inet } from '@/api/types';

/** One word for an Inet: every document signed / sent, some generated, or nothing yet. */
export function inetState(n: Inet): 'signed' | 'sent' | 'generated' | 'none' {
  if (n.documents.length && n.documents.every((d) => d.signed)) return 'signed';
  if (n.documents.length && n.documents.every((d) => d.sent)) return 'sent';
  if (n.documents.some((d) => d.generated)) return 'generated';
  return 'none';
}
