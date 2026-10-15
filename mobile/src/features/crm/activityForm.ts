import type { Activity, ActivityInput, ActivityStatus, ActivityType, CallDirection, MeetingType } from '@/api/types';
import { htmlToText, parseDate, textToHtml, toApiDateTime } from '@/lib/format';

export interface Ref {
  id: number;
  label: string;
}

/** What the form holds. Contact and opportunity always belong to the chosen lead. */
export interface ActivityDraft {
  activity_type: ActivityType;
  topic: string;
  status: ActivityStatus;
  start: Date | null;
  call_direction: CallDirection;
  call_duration: string;
  meeting_type: MeetingType;
  meeting_duration: string;
  meeting_location: string;
  lead: Ref | null;
  contact: Ref | null;
  opportunity: Ref | null;
  notify_me: boolean;
  notify_before: number;
  description: string;
}

export function newDraft(type: ActivityType = 'CALL', lead: Ref | null = null, now: Date = new Date()): ActivityDraft {
  const start = new Date(now);
  start.setSeconds(0, 0);
  return {
    activity_type: type,
    topic: '',
    status: type === 'CALL' ? 'DONE' : 'PLANNED',
    start,
    call_direction: 'OUTBOUND',
    call_duration: '',
    meeting_type: 'CUSTOMER_SITE',
    meeting_duration: '',
    meeting_location: '',
    lead,
    contact: null,
    opportunity: null,
    notify_me: false,
    notify_before: 15,
    description: '',
  };
}

export function draftFromActivity(a: Activity): ActivityDraft {
  return {
    activity_type: a.activity_type,
    topic: a.topic,
    status: a.status,
    start: parseDate(a.start_at),
    call_direction: a.call_direction ?? 'OUTBOUND',
    call_duration: a.call_duration != null ? String(a.call_duration) : '',
    meeting_type: a.meeting_type ?? 'CUSTOMER_SITE',
    meeting_duration: a.meeting_duration != null ? String(a.meeting_duration) : '',
    meeting_location: a.meeting_location ?? '',
    lead: a.lead_id ? { id: a.lead_id, label: a.lead_name ?? `#${a.lead_id}` } : null,
    contact: a.contact_id ? { id: a.contact_id, label: a.contact_name ?? `#${a.contact_id}` } : null,
    opportunity: a.opportunity_id ? { id: a.opportunity_id, label: a.opportunity_name ?? `#${a.opportunity_id}` } : null,
    notify_me: !!a.notify_me,
    notify_before: a.notify_before ?? 15,
    description: htmlToText(a.description),
  };
}

/** Changing the lead drops a contact / opportunity of the old lead. */
export function withLead(d: ActivityDraft, lead: Ref | null): ActivityDraft {
  if (lead?.id === d.lead?.id) return d;
  return { ...d, lead, contact: null, opportunity: null };
}

export type DraftErrors = Partial<Record<'topic' | 'lead_id' | 'start_at' | 'call_duration' | 'meeting_duration', 'required' | 'number'>>;

export function validateDraft(d: ActivityDraft): DraftErrors {
  const e: DraftErrors = {};
  if (!d.topic.trim()) e.topic = 'required';
  if (!d.lead) e.lead_id = 'required';
  if (d.notify_me && !d.start) e.start_at = 'required';
  if (d.activity_type === 'CALL' && d.call_duration.trim() && !/^\d{1,4}$/.test(d.call_duration.trim())) e.call_duration = 'number';
  if (d.activity_type === 'MEETING' && d.meeting_duration.trim() && !/^\d{1,4}$/.test(d.meeting_duration.trim())) e.meeting_duration = 'number';
  return e;
}

const num = (s: string) => (s.trim() ? Number(s.trim()) : null);

/** The body for POST /crm/activities (or /crm/activities/{id}): all fields, so clearing one clears it. */
export function draftToInput(d: ActivityDraft): ActivityInput {
  const call = d.activity_type === 'CALL';
  const meeting = d.activity_type === 'MEETING';
  return {
    activity_type: d.activity_type,
    topic: d.topic.trim().slice(0, 255),
    status: d.status,
    start_at: d.start ? toApiDateTime(d.start) : null,
    call_direction: call ? d.call_direction : null,
    call_duration: call ? num(d.call_duration) : null,
    meeting_type: meeting ? d.meeting_type : null,
    meeting_duration: meeting ? num(d.meeting_duration) : null,
    meeting_location: meeting ? d.meeting_location.trim() || null : null,
    lead_id: d.lead!.id,
    contact_id: d.contact?.id ?? null,
    opportunity_id: d.opportunity?.id ?? null,
    notify_me: d.notify_me,
    notify_before: d.notify_me ? d.notify_before : 0,
    description: d.description.trim() ? textToHtml(d.description) : null,
  };
}
