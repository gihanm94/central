import { fireEvent, render, screen } from '@testing-library/react-native';

import { ApiError } from '@/api/errors';
import { settingsStore } from '@/state/settings';

import SignInScreen from '../sign-in';

jest.mock('expo-router', () => require('@/testing/routerMock').expoRouterMock);
jest.mock('expo-device', () => ({ modelName: 'iPhone 15' }));
jest.mock('@/security/installId', () => ({ getInstallId: async () => 'i-test-install-id' }));

const mockSignIn = jest.fn();
jest.mock('@/state/session', () => ({
  signIn: (...a: unknown[]) => mockSignIn(...a),
  clearNotice: jest.fn(),
  useSession: () => ({ status: 'signedOut', profile: null, notice: null }),
}));

beforeEach(() => {
  mockSignIn.mockReset();
  settingsStore.set({ serverUrl: 'https://erp.example.com', language: 'en', appLock: true, lockAfterMinutes: 1 });
});

async function fill() {
  await fireEvent.changeText(screen.getByTestId('email'), 'somchai@acme.com');
  await fireEvent.changeText(screen.getByTestId('password'), 'secret');
}

describe('Sign-in screen', () => {
  it('sends e-mail, password, the default device name, install id and platform', async () => {
    mockSignIn.mockResolvedValue({});
    await render(<SignInScreen />);
    await fill();
    expect(screen.getByTestId('device-name').props.value).toBe("Somchai's iPhone 15");
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(mockSignIn).toHaveBeenCalledWith({ email: 'somchai@acme.com', password: 'secret', device_name: "Somchai's iPhone 15", device_id: 'i-test-install-id', platform: 'ios' });
  });

  it.each([
    ['invalid_credentials', 401, 'These details do not match an active account.'],
    ['no_api_access', 403, 'Your account may not use the mobile app. Ask an administrator.'],
  ])('shows the server message for %s', async (code, status, message) => {
    mockSignIn.mockRejectedValue(new ApiError({ status, code, message }));
    await render(<SignInScreen />);
    await fill();
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(await screen.findByText(message)).toBeOnTheScreen();
    expect(screen.getByTestId('password').props.value).toBe('');
  });

  it('counts down a lockout from Retry-After and disables the button', async () => {
    mockSignIn.mockRejectedValue(new ApiError({ status: 429, code: 'locked', message: 'Too many failed attempts.', retryAfter: 600 }));
    await render(<SignInScreen />);
    await fill();
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(await screen.findByTestId('sign-in-locked')).toHaveTextContent(/Too many failed attempts\.\s*Try again in (10:00|9:59)\./);
    expect(screen.getByTestId('sign-in')).toBeDisabled();
  });

  it('checks empty fields before calling the server', async () => {
    await render(<SignInScreen />);
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(screen.getByText('Enter your e-mail address.')).toBeOnTheScreen();
    expect(mockSignIn).not.toHaveBeenCalled();
  });

  it('refuses to sign in to an http:// server in a production build', async () => {
    settingsStore.set((s) => ({ ...s, serverUrl: 'http://erp.example.com' }));
    await render(<SignInScreen />);
    expect(screen.getByTestId('sign-in')).toBeDisabled();
  });
});
