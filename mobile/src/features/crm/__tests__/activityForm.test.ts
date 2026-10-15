import type { Activity } from '@/api/types';

import { draftFromActivity, draftToInput, newDraft, validateDraft, withLead } from '../activityForm';

const now = new Date(2026, 9, 15, 10, 0, 30);

describe('activity form', () => {
  it('a new call is DONE now, a meeting PLANNED', () => {
    expect(newDraft('CALL', null, now)).toMatchObject({ status: 'DONE', start: new Date(2026, 9, 15, 10, 0, 0) });
    expect(newDraft('MEETING', null, now).status).toBe('PLANNED');
  });

  it('lead is required (same rule as the website)', () => {
    const d = { ...newDraft('CALL', null, now), topic: 'Follow-up' };
    expect(validateDraft(d)).toEqual({ lead_id: 'required' });
    expect(validateDraft({ ...d, lead: { id: 7, label: 'Andaman' } })).toEqual({});
  });

  it('changing the lead clears contact and opportunity', () => {
    const d = { ...newDraft('CALL', { id: 7, label: 'A' }, now), contact: { id: 8, label: 'C' }, opportunity: { id: 9, label: 'O' } };
    expect(withLead(d, { id: 7, label: 'A' })).toBe(d);
    expect(withLead(d, { id: 3, label: 'B' })).toMatchObject({ lead: { id: 3 }, contact: null, opportunity: null });
  });

  it('builds a call body without meeting fields', () => {
    const d = { ...newDraft('CALL', { id: 7, label: 'A' }, now), topic: ' Quote ', call_duration: '12', contact: { id: 8, label: 'C' }, description: 'Asked <price>' };
    expect(draftToInput(d)).toEqual({
      activity_type: 'CALL',
      topic: 'Quote',
      status: 'DONE',
      start_at: '2026-10-15 10:00',
      call_direction: 'OUTBOUND',
      call_duration: 12,
      meeting_type: null,
      meeting_duration: null,
      meeting_location: null,
      lead_id: 7,
      contact_id: 8,
      opportunity_id: null,
      notify_me: false,
      notify_before: 0,
      description: '<p>Asked &lt;price&gt;</p>',
    });
  });

  it('builds a meeting body with a reminder', () => {
    const d = { ...newDraft('MEETING', { id: 7, label: 'A' }, now), topic: 'Visit', meeting_type: 'ONLINE' as const, meeting_duration: '45', meeting_location: 'Teams', notify_me: true, notify_before: 30 };
    expect(draftToInput(d)).toMatchObject({ call_direction: null, call_duration: null, meeting_type: 'ONLINE', meeting_duration: 45, meeting_location: 'Teams', notify_me: true, notify_before: 30 });
  });

  it('checks numbers', () => {
    const d = { ...newDraft('CALL', { id: 7, label: 'A' }, now), topic: 'x', call_duration: 'ten' };
    expect(validateDraft(d)).toEqual({ call_duration: 'number' });
  });

  it('round-trips a stored activity', () => {
    const a = {
      id: 10, code: null, activity_type: 'CALL', topic: 'Test', status: 'DONE', start_at: '2026-10-15 10:00:00+07', call_direction: 'INBOUND', call_duration: 5,
      meeting_type: null, meeting_duration: null, meeting_location: null, notify_me: false, notify_before: 0, description: '<p>a &amp; b</p>',
      lead_id: 7, lead_name: 'Andaman', contact_id: null, contact_name: null, opportunity_id: 2, opportunity_name: 'Deal', owner_id: 5, owner: 'T', updated_at: '',
    } as Activity;
    const d = draftFromActivity(a);
    expect(d).toMatchObject({ topic: 'Test', call_direction: 'INBOUND', call_duration: '5', lead: { id: 7, label: 'Andaman' }, opportunity: { id: 2, label: 'Deal' }, description: 'a & b' });
    expect(draftToInput(d).description).toBe('<p>a &amp; b</p>');
  });
});
