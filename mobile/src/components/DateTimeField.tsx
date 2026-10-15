import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker';
import { useState } from 'react';
import { Modal, Platform, Pressable, Text, View } from 'react-native';

import { useLanguage, useT } from '@/i18n';
import { formatDateTime, toApiDateTime } from '@/lib/format';

import { colors, space } from './theme';
import { Button, styles } from './ui';

/** Date + time input. Android: the system date then time dialogs; iOS: an inline picker in a sheet. */
export function DateTimeField({ label, value, onChange, error }: { label: string; value: Date | null; onChange: (d: Date) => void; error?: string | null }) {
  const t = useT();
  const lang = useLanguage();
  const [iosOpen, setIosOpen] = useState(false);
  const [draft, setDraft] = useState<Date>(value ?? new Date());

  function open() {
    const start = value ?? new Date();
    if (Platform.OS === 'android') {
      DateTimePickerAndroid.open({
        value: start,
        mode: 'date',
        onChange: (e, date) => {
          if (e.type !== 'set' || !date) return;
          DateTimePickerAndroid.open({
            value: date,
            mode: 'time',
            is24Hour: true,
            onChange: (e2, time) => {
              if (e2.type !== 'set' || !time) return;
              const d = new Date(date);
              d.setHours(time.getHours(), time.getMinutes(), 0, 0);
              onChange(d);
            },
          });
        },
      });
    } else {
      setDraft(start);
      setIosOpen(true);
    }
  }

  return (
    <View style={styles.inputWrap}>
      <Text style={styles.inputLabel}>{label}</Text>
      <Pressable onPress={open} accessibilityRole="button" accessibilityLabel={label} style={[styles.input, !!error && styles.inputError]}>
        <Text style={{ fontSize: 15, color: value ? colors.text : colors.faint }}>{value ? formatDateTime(toApiDateTime(value), lang) : t('common.choose')}</Text>
      </Pressable>
      {!!error && <Text style={styles.errorText}>{error}</Text>}
      {Platform.OS === 'ios' && (
        <Modal visible={iosOpen} transparent animationType="slide" onRequestClose={() => setIosOpen(false)}>
          <View style={{ flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.3)' }}>
            <View style={{ backgroundColor: colors.card, padding: space.lg, gap: space.md }}>
              <DateTimePicker value={draft} mode="datetime" display="inline" onChange={(_e, d) => d && setDraft(d)} locale={lang === 'th' ? 'th-TH' : 'en-GB'} />
              <Button
                title={t('common.done')}
                onPress={() => {
                  onChange(draft);
                  setIosOpen(false);
                }}
              />
            </View>
          </View>
        </Modal>
      )}
    </View>
  );
}
