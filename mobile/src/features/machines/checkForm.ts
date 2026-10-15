import type { Answer, CheckInput, ChecklistItem } from '@/api/types';

export interface AnswerDraft {
  answer: string;
  remark: string;
}

export type Drafts = Record<number, AnswerDraft>;

export type ItemProblem = 'missing' | 'remark_required';

/** Same rules as the server (Checklists::answers): every item answered, NG needs a remark. */
export function checkProblems(items: ChecklistItem[], drafts: Drafts): Record<number, ItemProblem> {
  const out: Record<number, ItemProblem> = {};
  for (const item of items) {
    const d = drafts[item.id];
    const a = d?.answer.trim() ?? '';
    if (item.answer_type === 'choice') {
      if (!(['OK', 'NG', 'NA'] as Answer[]).includes(a as Answer)) out[item.id] = 'missing';
      else if (a === 'NG' && !(d?.remark.trim() ?? '')) out[item.id] = 'remark_required';
    } else if (!a) {
      out[item.id] = 'missing';
    }
  }
  return out;
}

/** Drafts → the body of POST /machines/m/{code}/check (remarks only where they belong). */
export function buildCheckInput(items: ChecklistItem[], drafts: Drafts, machineStatus: string, note: string): CheckInput {
  const answers: CheckInput['answers'] = {};
  for (const item of items) {
    const d = drafts[item.id];
    if (!d) continue;
    const answer = d.answer.trim().slice(0, 200);
    const remark = d.remark.trim().slice(0, 500);
    answers[String(item.id)] = remark && item.answer_type === 'choice' ? { answer, remark } : { answer };
  }
  const input: CheckInput = { machine_status: machineStatus, answers };
  if (note.trim()) input.machine_note = note.trim();
  return input;
}

export function countNg(drafts: Drafts): number {
  return Object.values(drafts).filter((d) => d.answer === 'NG').length;
}
