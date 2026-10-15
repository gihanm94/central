/** expo-router stand-in for screen tests. */
import type { ReactNode } from 'react';
import { Text } from 'react-native';

export const router = { push: jest.fn(), replace: jest.fn(), back: jest.fn() };
export let params: Record<string, string> = {};
export function setParams(p: Record<string, string>) {
  params = p;
}

export const expoRouterMock = {
  router,
  useRouter: () => router,
  useLocalSearchParams: () => params,
  useFocusEffect: () => undefined,
  Link: ({ children }: { children: ReactNode }) => <Text>{children}</Text>,
  Redirect: () => null,
  Stack: Object.assign(() => null, { Screen: () => null, Protected: ({ children }: { children: ReactNode }) => children }),
};
