import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * APP_VARIANT=development | preview | production (default production), set per EAS profile in eas.json.
 * Only "development" may talk plain http:// to the local machine (localhost, 127.0.0.1, 10.0.2.2).
 * EXPO_PUBLIC_API_URL is the build-time default server; people can change it on the sign-in screen.
 */
const variant = process.env.APP_VARIANT ?? 'production';
const isDev = variant === 'development';
const suffix = isDev ? '.dev' : variant === 'preview' ? '.preview' : '';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: isDev ? 'Acme Dev' : variant === 'preview' ? 'Acme Preview' : 'Acme',
  slug: 'acme-mobile',
  scheme: 'acme',
  version: '1.0.0',
  orientation: 'portrait',
  icon: './assets/icon.png',
  userInterfaceStyle: 'light',
  ios: {
    bundleIdentifier: `com.acmeinter.mobile${suffix}`,
    supportsTablet: true,
    infoPlist: {
      ITSAppUsesNonExemptEncryption: false,
      // Development builds may reach a backend on the local network over http.
      ...(isDev ? { NSAppTransportSecurity: { NSAllowsLocalNetworking: true } } : {}),
    },
  },
  android: {
    package: `com.acmeinter.mobile${suffix}`,
    allowBackup: false,
    adaptiveIcon: {
      backgroundColor: '#E6F4FE',
      foregroundImage: './assets/android-icon-foreground.png',
      backgroundImage: './assets/android-icon-background.png',
      monochromeImage: './assets/android-icon-monochrome.png',
    },
    permissions: ['android.permission.CAMERA', 'android.permission.USE_BIOMETRIC'],
    blockedPermissions: ['android.permission.RECORD_AUDIO'],
    predictiveBackGestureEnabled: false,
  },
  plugins: [
    'expo-router',
    'expo-localization',
    'expo-sharing',
    'expo-image',
    '@react-native-community/datetimepicker',
    [
      'expo-camera',
      {
        cameraPermission: 'Allow $(PRODUCT_NAME) to use the camera to scan machine QR codes and take photos for checklists.',
        microphonePermission: false,
        recordAudioAndroid: false,
      },
    ],
    ['expo-local-authentication', { faceIDPermission: 'Allow $(PRODUCT_NAME) to use Face ID to unlock the app.' }],
    ['expo-secure-store', { faceIDPermission: false }],
    ['expo-build-properties', { android: { usesCleartextTraffic: isDev } }],
  ],
  extra: {
    variant,
    defaultApiUrl: process.env.EXPO_PUBLIC_API_URL ?? '',
    allowInsecureLocal: isDev,
  },
});
