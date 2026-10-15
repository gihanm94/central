import { isApiError } from '@/api/errors';
import type { TKey } from '@/i18n';
import { PhotoTooLargeError } from '@/lib/photo';

type T = (key: TKey, params?: Record<string, string | number>) => string;

/** A message to show for any error. The server's own message wins (it is already translated). */
export function errorMessage(err: unknown, t: T): string {
  if (err instanceof PhotoTooLargeError) return t('errors.photo_too_large');
  if (!isApiError(err)) return t('errors.generic');
  switch (err.code) {
    case 'network':
      return t('errors.network');
    case 'no_server':
      return t('errors.no_server');
    case 'module_unavailable':
      return err.message || t('errors.module_unavailable');
    case 'rate_limited':
      return err.message || t('errors.rate_limited', { s: err.retryAfter ?? 60 });
    case 'bad_response':
    case 'http_error':
      return t('errors.generic');
    case 'server_error':
      return err.message || t('errors.server', { ref: err.requestId ?? '—' });
    default:
      return err.message || t('errors.generic');
  }
}
