export const colors = {
  bg: '#F4F6F8',
  card: '#FFFFFF',
  text: '#111827',
  muted: '#6B7280',
  faint: '#9CA3AF',
  border: '#E5E7EB',
  primary: '#C2410C',
  primaryText: '#FFFFFF',
  primarySoft: '#FFEDD5',
  danger: '#B91C1C',
  dangerSoft: '#FEE2E2',
  success: '#15803D',
  successSoft: '#DCFCE7',
  warn: '#B45309',
  warnSoft: '#FEF3C7',
  info: '#1D4ED8',
  infoSoft: '#DBEAFE',
  neutralSoft: '#F3F4F6',
};

export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24 };
export const radius = { sm: 6, md: 10, lg: 14 };
export const font = { small: 13, body: 15, title: 17, h2: 20, h1: 26 };

export type Tone = 'neutral' | 'info' | 'success' | 'warn' | 'danger' | 'primary';

export const tones: Record<Tone, { bg: string; fg: string }> = {
  neutral: { bg: colors.neutralSoft, fg: colors.muted },
  info: { bg: colors.infoSoft, fg: colors.info },
  success: { bg: colors.successSoft, fg: colors.success },
  warn: { bg: colors.warnSoft, fg: colors.warn },
  danger: { bg: colors.dangerSoft, fg: colors.danger },
  primary: { bg: colors.primarySoft, fg: colors.primary },
};
