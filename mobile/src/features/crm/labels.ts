import type { ActivityStatus, ActivityType, OpportunityStage, TaskStatus } from '@/api/types';
import type { Tone } from '@/components/theme';
import type { TKey } from '@/i18n';

export const stageKey: Record<OpportunityStage, TKey> = {
  QUALIFICATION: 'crm.stage.QUALIFICATION',
  SURVEY_PROPOSAL: 'crm.stage.SURVEY_PROPOSAL',
  EVALUATION_TESTING: 'crm.stage.EVALUATION_TESTING',
  NEGOTIATION: 'crm.stage.NEGOTIATION',
  CLOSED_WON: 'crm.stage.CLOSED_WON',
  CLOSED_LOST: 'crm.stage.CLOSED_LOST',
  ON_HOLD: 'crm.stage.ON_HOLD',
  CANCEL: 'crm.stage.CANCEL',
};

export const stageTone: Record<OpportunityStage, Tone> = {
  QUALIFICATION: 'info',
  SURVEY_PROPOSAL: 'info',
  EVALUATION_TESTING: 'info',
  NEGOTIATION: 'primary',
  CLOSED_WON: 'success',
  CLOSED_LOST: 'danger',
  ON_HOLD: 'warn',
  CANCEL: 'neutral',
};

export const activityTypeKey: Record<ActivityType, TKey> = {
  CALL: 'crm.type.CALL',
  MEETING: 'crm.type.MEETING',
  EMAIL: 'crm.type.EMAIL',
  TASK: 'crm.type.TASK',
};

export const activityStatusKey: Record<ActivityStatus, TKey> = {
  PLANNED: 'crm.activityStatus.PLANNED',
  DONE: 'crm.activityStatus.DONE',
  CANCELLED: 'crm.activityStatus.CANCELLED',
};

export const activityStatusTone: Record<ActivityStatus, Tone> = { PLANNED: 'info', DONE: 'success', CANCELLED: 'neutral' };

export const taskStatusKey: Record<TaskStatus, TKey> = {
  TODO: 'crm.taskStatus.TODO',
  IN_PROGRESS: 'crm.taskStatus.IN_PROGRESS',
  BLOCKED: 'crm.taskStatus.BLOCKED',
  DONE: 'crm.taskStatus.DONE',
  CANCELLED: 'crm.taskStatus.CANCELLED',
};

export const taskStatusTone: Record<TaskStatus, Tone> = { TODO: 'neutral', IN_PROGRESS: 'info', BLOCKED: 'warn', DONE: 'success', CANCELLED: 'danger' };
