import * as SecureStore from 'expo-secure-store';

import type { TokenPair, TokenStore } from '@/api/tokens';
import { deserializePair, serializePair } from '@/api/tokens';

/**
 * Keychain (iOS) / Keystore-backed storage (Android), "this device only": never in a backup or on
 * another phone. "After first unlock" (not "when unlocked") so a token refresh that finishes while
 * the phone locks can still be written; a lost write would make the server see the old refresh
 * token again, treat it as stolen and sign the device out.
 */
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY };

export const KEYS = {
  tokens: 'acme.tokens',
  installId: 'acme.install_id',
  settings: 'acme.settings',
  profile: 'acme.profile',
} as const;

export async function readSecure(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key, OPTIONS);
  } catch {
    return null; // e.g. the keychain item became unreadable after a restore: treat as absent
  }
}

export async function writeSecure(key: string, value: string): Promise<void> {
  await SecureStore.setItemAsync(key, value, OPTIONS);
}

export async function deleteSecure(key: string): Promise<void> {
  await SecureStore.deleteItemAsync(key, OPTIONS);
}

/** The API tokens live only here, never in AsyncStorage, files or logs. */
export const secureTokenStore: TokenStore = {
  async load(): Promise<TokenPair | null> {
    return deserializePair(await readSecure(KEYS.tokens));
  },
  async save(pair: TokenPair): Promise<void> {
    await writeSecure(KEYS.tokens, serializePair(pair));
  },
  async clear(): Promise<void> {
    await deleteSecure(KEYS.tokens);
  },
};
