import { useMutation, useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert, StyleSheet, Text, View } from 'react-native';

import { isApiError } from '@/api/errors';
import type { ChecklistItem } from '@/api/types';
import { colors, radius, space } from '@/components/theme';
import { Banner, Button, Card, ErrorView, Loading, Screen, Section, Segmented, TextField } from '@/components/ui';
import { PhotoCapture } from '@/features/machines/components';
import { buildCheckInput, checkProblems, type Drafts } from '@/features/machines/checkForm';
import { machineStatus } from '@/features/machines/labels';
import { useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { deleteQuietly } from '@/lib/files';
import type { PreparedPhoto } from '@/lib/photo';
import { api, queryClient } from '@/state/session';

export default function CheckScreen() {
  const t = useT();
  const { code } = useLocalSearchParams<{ code: string }>();
  const q = useQuery({ queryKey: ['machines', 'machine', code], queryFn: () => api.machines.machine(code) });
  const [drafts, setDrafts] = useState<Drafts>({});
  const [status, setStatus] = useState<string>('');
  const [note, setNote] = useState('');
  const [photo, setPhoto] = useState<PreparedPhoto | null>(null);
  const [camera, setCamera] = useState(false);
  const [showProblems, setShowProblems] = useState(false);

  const checklist = q.data?.data.checklist;
  useEffect(() => {
    if (checklist && !status) setStatus(q.data!.data.machine.machine_status && checklist.machine_statuses.includes(q.data!.data.machine.machine_status) ? q.data!.data.machine.machine_status : (checklist.machine_statuses[0] ?? ''));
  }, [checklist, status, q.data]);

  const save = useMutation({
    mutationFn: () => api.machines.submitCheck(code, buildCheckInput(checklist!.items, drafts, status, note), photo ?? undefined),
    onSuccess: (rec) => {
      deleteQuietly(photo?.uri);
      void queryClient.invalidateQueries({ queryKey: ['machines'] });
      queryClient.setQueryData(['machines', 'checklist', rec.data.id], rec);
      Alert.alert(t('machines.saved'), rec.data.recheck ? t('machines.savedRecheck') : t('machines.savedThanks'));
      router.replace(`/machines/check/${rec.data.id}`);
    },
  });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  if (!checklist || !checklist.can_check) return <ErrorView error={new Error(t('machines.noPermission'))} />;

  const problems = checkProblems(checklist.items, drafts);
  const problemCount = Object.keys(problems).length;
  const set = (id: number, patch: Partial<Drafts[number]>) => setDrafts((d) => ({ ...d, [id]: { answer: '', remark: '', ...d[id], ...patch } }));
  const serverFields = isApiError(save.error) ? save.error.fields : undefined;

  function submit() {
    setShowProblems(true);
    if (problemCount > 0 || !status) return;
    save.mutate();
  }

  return (
    <>
      <Stack.Screen options={{ title: t('machines.checkTitle', { code }) }} />
      <Screen keyboard>
        {checklist.recheck && <Banner tone="info" text={t('machines.recheckInfo')} />}
        {save.isError && <Banner tone="danger" text={serverFields?.answers ?? errorMessage(save.error, t)} testID="check-error" />}

        {checklist.items.map((item, i) => (
          <ItemCard key={item.id} index={i + 1} item={item} draft={drafts[item.id]} problem={showProblems ? problems[item.id] : undefined} onChange={(p) => set(item.id, p)} />
        ))}

        <Section title={t('machines.machineStatusLabel')}>
          <Segmented
            options={checklist.machine_statuses.map((s) => {
              const l = machineStatus(s);
              return { value: s, label: l.key ? t(l.key) : s, tone: l.tone };
            })}
            value={status}
            onChange={setStatus}
            error={serverFields?.machine_status}
          />
        </Section>

        <TextField label={t('common.note')} value={note} onChangeText={setNote} multiline maxLength={1000} />

        <Section title={t('machines.photo')}>
          {photo ? (
            <View style={{ gap: space.sm }}>
              <Image source={{ uri: photo.uri }} style={styles.photo} contentFit="cover" />
              <Text style={{ color: colors.muted }}>{t('machines.photoSize', { kb: Math.round(photo.size / 1024) })}</Text>
              <Button
                title={t('machines.removePhoto')}
                variant="danger"
                small
                onPress={() => {
                  deleteQuietly(photo.uri);
                  setPhoto(null);
                }}
              />
            </View>
          ) : (
            <Button title={t('machines.addPhoto')} variant="secondary" onPress={() => setCamera(true)} />
          )}
        </Section>

        {showProblems && problemCount > 0 && <Banner tone="danger" text={t('machines.answerAll', { n: problemCount })} testID="answer-all" />}
        <Button title={t('machines.saveCheck')} onPress={submit} loading={save.isPending} testID="save-check" />
      </Screen>
      <PhotoCapture
        visible={camera}
        onClose={() => setCamera(false)}
        onPhoto={(p) => {
          deleteQuietly(photo?.uri);
          setPhoto(p);
        }}
      />
    </>
  );
}

function ItemCard({
  index,
  item,
  draft,
  problem,
  onChange,
}: {
  index: number;
  item: ChecklistItem;
  draft?: { answer: string; remark: string };
  problem?: 'missing' | 'remark_required';
  onChange: (p: { answer?: string; remark?: string }) => void;
}) {
  const t = useT();
  return (
    <Card style={[styles.item, problem && styles.itemProblem]}>
      <Text style={styles.question}>
        {index}. {item.question}
      </Text>
      {!!item.description && <Text style={styles.desc}>{item.description}</Text>}
      {item.answer_type === 'choice' ? (
        <>
          <Segmented
            testIDPrefix={`item-${item.id}`}
            options={[
              { value: 'OK', label: 'OK', tone: 'success' },
              { value: 'NG', label: 'NG', tone: 'danger' },
              { value: 'NA', label: 'N/A', tone: 'neutral' },
            ]}
            value={draft?.answer}
            onChange={(answer) => onChange({ answer })}
            error={problem === 'missing' ? t('machines.pickAnswer') : null}
          />
          {draft?.answer === 'NG' && (
            <TextField
              label={t('machines.remark')}
              value={draft.remark}
              onChangeText={(remark) => onChange({ remark })}
              placeholder={t('machines.remarkPlaceholder')}
              maxLength={500}
              error={problem === 'remark_required' ? t('machines.remarkRequired') : null}
              testID={`remark-${item.id}`}
            />
          )}
        </>
      ) : (
        <TextField
          label={t('machines.answer')}
          value={draft?.answer ?? ''}
          onChangeText={(answer) => onChange({ answer })}
          maxLength={200}
          error={problem === 'missing' ? t('machines.typeAnswer') : null}
          testID={`text-${item.id}`}
        />
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  item: { marginBottom: space.md },
  itemProblem: { borderColor: colors.danger, borderWidth: 1 },
  question: { fontSize: 16, fontWeight: '700', color: colors.text, marginBottom: 4 },
  desc: { fontSize: 13, color: colors.muted, marginBottom: space.sm },
  photo: { height: 200, borderRadius: radius.md },
});
