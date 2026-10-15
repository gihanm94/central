import type { ActivityStatus } from '@/api/types';
import { toApiDate } from '@/lib/format';

export type When = 'all' | 'today' | 'overdue' | 'upcoming';

/** today / overdue / upcoming as API filters (overdue = still planned, before today). */
export function whenFilter(when: When, now = new Date()): { from?: string; to?: string; status?: ActivityStatus } {
  const today = toApiDate(now);
  const yesterday = toApiDate(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1));
  switch (when) {
    case 'today':
      return { from: today, to: today };
    case 'overdue':
      return { to: yesterday, status: 'PLANNED' };
    case 'upcoming':
      return { from: today, status: 'PLANNED' };
    default:
      return {};
  }
}
