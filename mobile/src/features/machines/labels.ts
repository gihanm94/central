import type { Tone } from '@/components/theme';
import type { TKey } from '@/i18n';

/** checklist_record.checklist_status → label key and colour. Unknown values are shown as they are. */
export function checkStatus(status: string): { key: TKey | null; tone: Tone } {
  const late = status.endsWith('-OVERDUE');
  const base = status.replace('-OVERDUE', '');
  const map: Record<string, TKey> = {
    COMPLETED: 'machines.status.completed',
    'PENDING SUPERVISOR': late ? 'machines.status.supervisorLate' : 'machines.status.pendingSupervisor',
    'PENDING MANAGER': late ? 'machines.status.managerLate' : 'machines.status.pendingManager',
    PENDING: 'machines.status.pending',
  };
  const key = map[base] ?? null;
  return { key, tone: base === 'COMPLETED' ? 'success' : late ? 'danger' : base.startsWith('PENDING') ? 'warn' : 'neutral' };
}

export function machineStatus(status: string): { key: TKey | null; tone: Tone } {
  switch (status) {
    case 'OPERATIONAL':
      return { key: 'machines.machineStatus.operational', tone: 'success' };
    case 'UNDER MAINTENANCE':
      return { key: 'machines.machineStatus.maintenance', tone: 'warn' };
    default:
      return { key: null, tone: 'neutral' };
  }
}
