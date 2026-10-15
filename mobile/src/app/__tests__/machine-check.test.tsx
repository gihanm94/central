import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import type { MachineScreen } from '@/api/types';
import { setParams } from '@/testing/routerMock';

import CheckScreen from '../(app)/machines/m/[code]/check';

jest.mock('expo-router', () => require('@/testing/routerMock').expoRouterMock);
jest.mock('expo-image', () => ({ Image: () => null }));
jest.mock('expo-camera', () => ({ CameraView: () => null, useCameraPermissions: () => [{ granted: true }, jest.fn()] }));

const mockMachine = jest.fn();
const mockSubmitCheck = jest.fn();
const mockClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { gcTime: Infinity } } });
jest.mock('@/state/session', () => ({
  api: { machines: { machine: (...a: unknown[]) => mockMachine(...a), submitCheck: (...a: unknown[]) => mockSubmitCheck(...a) } },
  get queryClient() {
    return mockClient;
  },
}));

const screenData: MachineScreen = {
  machine: { id: 1, machine_code: 'OPS-1', machine_name: 'Lathe', machine_status: 'OPERATIONAL', check_status: 'PENDING' } as MachineScreen['machine'],
  people: { responsible: null, supervisor: null, manager: null },
  checklist: {
    can_check: true,
    recheck: false,
    weekend: false,
    machine_statuses: ['OPERATIONAL', 'UNDER MAINTENANCE'],
    items: [
      { id: 12, question: 'Oil level', description: null, answer_type: 'choice', choices: ['OK', 'NG', 'NA'] },
      { id: 13, question: 'Temperature', description: null, answer_type: 'text', choices: null },
    ],
  },
  last_checks: [],
  next_maintenance: null,
  next_calibration: null,
};

const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={mockClient}>{children}</QueryClientProvider>;

beforeEach(() => {
  mockClient.clear();
  setParams({ code: 'OPS-1' });
  mockMachine.mockResolvedValue({ data: screenData });
  mockSubmitCheck.mockReset().mockResolvedValue({ data: { id: 99, recheck: false } });
  jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
});

describe("Today's check", () => {
  it('needs every answer and a remark for NG before it calls the server', async () => {
    await render(<CheckScreen />, { wrapper });
    await fireEvent.press(await screen.findByTestId('item-12-NG'));
    await fireEvent.press(screen.getByTestId('save-check'));
    expect(screen.getByText('Write what is wrong.')).toBeOnTheScreen();
    expect(screen.getByText('Type the answer.')).toBeOnTheScreen();
    expect(screen.getByTestId('answer-all')).toBeOnTheScreen();
    expect(mockSubmitCheck).not.toHaveBeenCalled();
  });

  it('submits the answers, remark and machine status', async () => {
    await render(<CheckScreen />, { wrapper });
    await fireEvent.press(await screen.findByTestId('item-12-NG'));
    await fireEvent.changeText(screen.getByTestId('remark-12'), 'Leaking');
    await fireEvent.changeText(screen.getByTestId('text-13'), '42 °C');
    await fireEvent.press(screen.getByRole('radio', { name: 'Under maintenance' }));
    await fireEvent.press(screen.getByTestId('save-check'));
    expect(mockSubmitCheck).toHaveBeenCalledWith(
      'OPS-1',
      { machine_status: 'UNDER MAINTENANCE', answers: { '12': { answer: 'NG', remark: 'Leaking' }, '13': { answer: '42 °C' } } },
      undefined,
    );
  });
});
