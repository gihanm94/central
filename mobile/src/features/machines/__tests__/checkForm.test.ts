import type { ChecklistItem } from '@/api/types';

import { buildCheckInput, checkProblems, countNg } from '../checkForm';

const items: ChecklistItem[] = [
  { id: 12, question: 'Oil', description: null, answer_type: 'choice', choices: ['OK', 'NG', 'NA'] },
  { id: 13, question: 'Temp', description: null, answer_type: 'text', choices: null },
  { id: 14, question: 'Belt', description: null, answer_type: 'choice', choices: ['OK', 'NG', 'NA'] },
];

describe('checkProblems', () => {
  it('needs every item answered', () => {
    expect(checkProblems(items, {})).toEqual({ 12: 'missing', 13: 'missing', 14: 'missing' });
  });

  it('needs a remark for NG', () => {
    expect(checkProblems(items, { 12: { answer: 'OK', remark: '' }, 13: { answer: '42', remark: '' }, 14: { answer: 'NG', remark: '  ' } })).toEqual({ 14: 'remark_required' });
  });

  it('is happy when all is answered', () => {
    expect(checkProblems(items, { 12: { answer: 'NA', remark: '' }, 13: { answer: '42 mm', remark: '' }, 14: { answer: 'NG', remark: 'Worn' } })).toEqual({});
  });

  it('rejects unknown choice values and blank text', () => {
    expect(checkProblems(items, { 12: { answer: 'YES', remark: '' }, 13: { answer: '   ', remark: '' }, 14: { answer: 'OK', remark: '' } })).toEqual({ 12: 'missing', 13: 'missing' });
  });
});

describe('buildCheckInput', () => {
  it('builds the API body with remarks only for choice items', () => {
    const input = buildCheckInput(
      items,
      { 12: { answer: 'OK', remark: '' }, 13: { answer: ' 42 mm ', remark: 'ignored' }, 14: { answer: 'NG', remark: ' Belt worn ' } },
      'OPERATIONAL',
      '  ',
    );
    expect(input).toEqual({
      machine_status: 'OPERATIONAL',
      answers: { '12': { answer: 'OK' }, '13': { answer: '42 mm' }, '14': { answer: 'NG', remark: 'Belt worn' } },
    });
  });

  it('adds the note when given', () => {
    expect(buildCheckInput(items, {}, 'UNDER MAINTENANCE', 'Noisy').machine_note).toBe('Noisy');
  });

  it('counts NG answers', () => {
    expect(countNg({ 1: { answer: 'NG', remark: 'x' }, 2: { answer: 'OK', remark: '' } })).toBe(1);
  });
});
