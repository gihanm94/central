import { en } from '../en';
import { th } from '../th';
import { resolveLanguage, translate } from '../index';

jest.mock('expo-localization', () => ({ getLocales: () => [{ languageCode: 'th' }] }));

const placeholders = (s: string) => (s.match(/\{\w+\}/g) ?? []).sort().join(',');

describe('translations', () => {
  it('Thai has every English key and no others', () => {
    expect(Object.keys(th).sort()).toEqual(Object.keys(en).sort());
  });

  it('keeps the same {placeholders}', () => {
    for (const k of Object.keys(en) as (keyof typeof en)[]) {
      expect([k, placeholders(th[k])]).toEqual([k, placeholders(en[k])]);
    }
  });

  it('has no empty texts', () => {
    for (const k of Object.keys(th) as (keyof typeof th)[]) expect(th[k].trim()).not.toBe('');
  });

  it('fills placeholders', () => {
    expect(translate('en', 'home.hello', { name: 'Somchai' })).toBe('Hello, Somchai');
    expect(translate('th', 'machines.answerAll', { n: 2 })).toBe('กรุณาตอบทุกข้อ (เหลือ 2 ข้อ)');
  });

  it('picks the language: setting, else the account, else the phone', () => {
    expect(resolveLanguage('en', 'th')).toBe('en');
    expect(resolveLanguage('system', 'en')).toBe('en');
    expect(resolveLanguage('system', null)).toBe('th');
  });
});
