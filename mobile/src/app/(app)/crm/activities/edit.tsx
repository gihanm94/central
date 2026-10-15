import { useMutation, useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';

import { isApiError } from '@/api/errors';
import { ACTIVITY_TYPES, CALL_DIRECTIONS, MEETING_TYPES, NOTIFY_BEFORE, type ActivityStatus, type ActivityType } from '@/api/types';
import { DateTimeField } from '@/components/DateTimeField';
import { PickerField } from '@/components/PickerSheet';
import { Banner, Button, ErrorView, Loading, Screen, Section, Segmented, TextField, Toggle } from '@/components/ui';
import { draftFromActivity, draftToInput, newDraft, validateDraft, withLead, type ActivityDraft, type DraftErrors, type Ref } from '@/features/crm/activityForm';
import { activityStatusKey, activityTypeKey } from '@/features/crm/labels';
import { useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { api, queryClient } from '@/state/session';

type Params = {
  id?: string;
  type?: string;
  lead_id?: string;
  lead_name?: string;
  contact_id?: string;
  contact_name?: string;
  opportunity_id?: string;
  opportunity_name?: string;
};

function ref(id?: string, label?: string): Ref | null {
  const n = Number(id);
  return n > 0 ? { id: n, label: label || `#${n}` } : null;
}

/** Log a call / meeting, or edit an activity (?id=). Lead required; contact and opportunity come from the chosen lead. */
export default function ActivityEditScreen() {
  const t = useT();
  const p = useLocalSearchParams<Params>();
  const editId = Number(p.id) || null;
  const existing = useQuery({ queryKey: ['crm', 'activity', editId], queryFn: () => api.crm.activity(editId!), enabled: !!editId });
  const [draft, setDraft] = useState<ActivityDraft | null>(() => {
    if (editId) return null;
    const type = (ACTIVITY_TYPES as readonly string[]).includes(p.type ?? '') ? (p.type as ActivityType) : 'CALL';
    return { ...newDraft(type, ref(p.lead_id, p.lead_name)), contact: ref(p.contact_id, p.contact_name), opportunity: ref(p.opportunity_id, p.opportunity_name) };
  });
  const [errors, setErrors] = useState<DraftErrors>({});
  /** Description text when the edit started: an untouched description is not sent, so website formatting stays. */
  const [originalDescription, setOriginalDescription] = useState<string | null>(null);

  useEffect(() => {
    if (editId && existing.data && !draft) {
      const d = draftFromActivity(existing.data.data);
      setDraft(d);
      setOriginalDescription(d.description);
    }
  }, [editId, existing.data, draft]);

  // a lead passed by id only: fetch its name
  const leadName = useQuery({ queryKey: ['crm', 'lead', draft?.lead?.id], queryFn: () => api.crm.lead(draft!.lead!.id), enabled: !!draft?.lead && draft.lead.label.startsWith('#') });
  useEffect(() => {
    const l = leadName.data?.data;
    if (l) setDraft((d) => (d && d.lead?.id === l.id ? { ...d, lead: { id: l.id, label: l.name_en } } : d));
  }, [leadName.data]);

  const save = useMutation({
    mutationFn: (d: ActivityDraft) => {
      if (!editId) return api.crm.createActivity(draftToInput(d));
      const { description, ...rest } = draftToInput(d);
      return api.crm.updateActivity(editId, d.description === originalDescription ? rest : { ...rest, description });
    },
    onSuccess: (res) => {
      queryClient.setQueryData(['crm', 'activity', res.data.id], res);
      void queryClient.invalidateQueries({ queryKey: ['crm'], predicate: (x) => x.queryKey[1] !== 'activity' });
      if (editId) router.back();
      else router.replace(`/crm/activities/${res.data.id}`);
    },
  });

  if (editId && existing.isError) return <ErrorView error={existing.error} onRetry={() => void existing.refetch()} />;
  if (!draft) return <Loading />;

  const set = (patch: Partial<ActivityDraft>) => setDraft((d) => (d ? { ...d, ...patch } : d));
  const server = isApiError(save.error) ? save.error.fields ?? {} : {};
  const local = (k: keyof DraftErrors) => (errors[k] === 'required' ? t('crm.required') : errors[k] === 'number' ? t('crm.wholeMinutes') : null);
  const err = (k: string) => (local(k as keyof DraftErrors) ?? server[k]) || null;

  function submit() {
    const e = validateDraft(draft!);
    setErrors(e);
    if (Object.keys(e).length === 0) save.mutate(draft!);
  }

  const title = editId ? t('crm.editActivity') : draft.activity_type === 'MEETING' ? t('crm.logMeeting') : draft.activity_type === 'CALL' ? t('crm.logCall') : t('crm.newActivity');

  return (
    <>
      <Stack.Screen options={{ title }} />
      <Screen keyboard>
        {save.isError && <Banner tone="danger" text={errorMessage(save.error, t)} testID="activity-error" />}
        <Segmented
          label={t('crm.type')}
          options={ACTIVITY_TYPES.map((x) => ({ value: x, label: t(activityTypeKey[x]) }))}
          value={draft.activity_type}
          onChange={(activity_type) => set({ activity_type })}
          error={server.activity_type}
        />
        <TextField label={t('crm.topic')} value={draft.topic} onChangeText={(topic) => set({ topic })} maxLength={255} error={err('topic')} testID="topic" />
        <PickerField
          testID="pick-lead"
          label={t('crm.leadRequired')}
          value={draft.lead}
          onChange={(lead) => setDraft((d) => (d ? withLead(d, lead) : d))}
          queryKey={['crm', 'pick', 'leads']}
          load={async (q) => (await api.crm.leads({ q, per_page: 30 })).data.map((l) => ({ id: l.id, label: l.name_en, sub: [l.code, l.name_th].filter(Boolean).join(' · ') }))}
          error={err('lead_id')}
        />
        <PickerField
          testID="pick-contact"
          label={t('crm.contactOptional')}
          value={draft.contact}
          optional
          disabled={!draft.lead}
          onChange={(contact) => set({ contact })}
          queryKey={['crm', 'pick', 'contacts', draft.lead?.id]}
          load={async (q) => (await api.crm.contacts({ q, lead_id: draft.lead?.id, per_page: 30 })).data.map((c) => ({ id: c.id, label: c.name_en, sub: c.job_title }))}
          error={err('contact_id')}
        />
        <PickerField
          testID="pick-opportunity"
          label={t('crm.opportunityOptional')}
          value={draft.opportunity}
          optional
          disabled={!draft.lead}
          onChange={(opportunity) => set({ opportunity })}
          queryKey={['crm', 'pick', 'opportunities', draft.lead?.id]}
          load={async (q) => (await api.crm.opportunities({ q, lead_id: draft.lead?.id, per_page: 30 })).data.map((o) => ({ id: o.id, label: o.name, sub: o.stage_label }))}
          error={err('opportunity_id')}
        />
        <Segmented<ActivityStatus>
          label={t('crm.status')}
          options={(['PLANNED', 'DONE', 'CANCELLED'] as const).map((s) => ({ value: s, label: t(activityStatusKey[s]) }))}
          value={draft.status}
          onChange={(status) => set({ status })}
          error={server.status}
        />
        <DateTimeField label={t('crm.when')} value={draft.start} onChange={(start) => set({ start })} error={err('start_at')} />

        {draft.activity_type === 'CALL' && (
          <Section>
            <Segmented label={t('crm.direction')} options={CALL_DIRECTIONS.map((x) => ({ value: x, label: t(x === 'INBOUND' ? 'crm.inbound' : 'crm.outbound') }))} value={draft.call_direction} onChange={(call_direction) => set({ call_direction })} />
            <TextField label={t('crm.minutes')} value={draft.call_duration} onChangeText={(call_duration) => set({ call_duration })} keyboardType="number-pad" maxLength={4} error={err('call_duration')} />
          </Section>
        )}
        {draft.activity_type === 'MEETING' && (
          <Section>
            <Segmented label={t('crm.meetingType')} options={MEETING_TYPES.map((x) => ({ value: x, label: t(`crm.meeting.${x}`) }))} value={draft.meeting_type} onChange={(meeting_type) => set({ meeting_type })} />
            <TextField label={t('crm.minutes')} value={draft.meeting_duration} onChangeText={(meeting_duration) => set({ meeting_duration })} keyboardType="number-pad" maxLength={4} error={err('meeting_duration')} />
            <TextField label={t('crm.location')} value={draft.meeting_location} onChangeText={(meeting_location) => set({ meeting_location })} maxLength={255} error={server.meeting_location} />
          </Section>
        )}

        <Toggle label={t('crm.remindMe')} value={draft.notify_me} onChange={(notify_me) => set({ notify_me })} />
        {draft.notify_me && (
          <Segmented
            options={NOTIFY_BEFORE.map((n) => ({ value: n, label: n === 0 ? t('crm.atStart') : n === 1440 ? t('crm.dayBefore') : t('crm.minutesBefore', { n }) }))}
            value={draft.notify_before}
            onChange={(notify_before) => set({ notify_before })}
          />
        )}
        <TextField label={t('crm.description')} value={draft.description} onChangeText={(description) => set({ description })} multiline maxLength={5000} error={server.description} />
        <Button title={t('common.save')} onPress={submit} loading={save.isPending} testID="save-activity" />
      </Screen>
    </>
  );
}
