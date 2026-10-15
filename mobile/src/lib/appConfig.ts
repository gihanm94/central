import Constants from 'expo-constants';

interface Extra {
  variant?: string;
  defaultApiUrl?: string;
  allowInsecureLocal?: boolean;
}

const extra = (Constants.expoConfig?.extra ?? {}) as Extra;

/** Build-time settings from app.config.ts. */
export const appConfig = {
  variant: extra.variant ?? 'production',
  /** Server people get unless they type another one (EXPO_PUBLIC_API_URL at build time). */
  defaultServerUrl: extra.defaultApiUrl ?? '',
  /** Only development builds may use http:// to the local machine. */
  allowInsecureLocal: extra.allowInsecureLocal === true,
  version: Constants.expoConfig?.version ?? '',
};
