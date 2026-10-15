import { getLocales } from 'expo-localization';
import { useCallback } from 'react';

import { createStore, useStore } from '@/lib/store';
import { settingsStore, type LanguageSetting } from '@/state/settings';

import { en, type TKey } from './en';
import { th } from './th';

export type Language = 'en' | 'th';
export type { TKey };
export type Params = Record<string, string | number>;

const dictionaries: Record<Language, Record<TKey, string>> = { en, th };

/** The language the app shows (and asks the server to answer in before sign-in). */
export const languageStore = createStore<Language>('en');

/** The person's language on the server (profile.locale), used when the app setting is "system". */
let profileLocale: string | null = null;

export function deviceLanguage(): Language {
  try {
    return getLocales()[0]?.languageCode === 'th' ? 'th' : 'en';
  } catch {
    return 'en';
  }
}

export function resolveLanguage(setting: LanguageSetting, profile: string | null = profileLocale): Language {
  if (setting === 'en' || setting === 'th') return setting;
  if (profile === 'th' || profile === 'en') return profile;
  return deviceLanguage();
}

export function applyLanguage(): void {
  languageStore.set(resolveLanguage(settingsStore.get().language));
}

export function setProfileLocale(locale: string | null): void {
  profileLocale = locale;
  applyLanguage();
}

settingsStore.subscribe(applyLanguage);

export function translate(lang: Language, key: TKey, params?: Params): string {
  const text = dictionaries[lang][key] ?? en[key] ?? key;
  if (!params) return text;
  return text.replace(/\{(\w+)\}/g, (m, name: string) => (params[name] !== undefined ? String(params[name]) : m));
}

/** Translate with the current language (outside React). */
export function t(key: TKey, params?: Params): string {
  return translate(languageStore.get(), key, params);
}

export function useLanguage(): Language {
  return useStore(languageStore);
}

/** `const t = useT(); t('crm.leads')` – re-renders when the language changes. */
export function useT(): (key: TKey, params?: Params) => string {
  const lang = useLanguage();
  return useCallback((key: TKey, params?: Params) => translate(lang, key, params), [lang]);
}
