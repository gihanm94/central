import { mailtoUrl, telUrl } from '@/lib/contactLinks';

import { whenFilter } from '../when';

jest.mock('react-native', () => ({ Linking: { openURL: jest.fn() } }));

describe('whenFilter', () => {
  const now = new Date(2026, 9, 1, 9, 0); // 1 Oct 2026
  it('maps today / overdue / upcoming to API filters', () => {
    expect(whenFilter('today', now)).toEqual({ from: '2026-10-01', to: '2026-10-01' });
    expect(whenFilter('overdue', now)).toEqual({ to: '2026-09-30', status: 'PLANNED' });
    expect(whenFilter('upcoming', now)).toEqual({ from: '2026-10-01', status: 'PLANNED' });
    expect(whenFilter('all', now)).toEqual({});
  });
});

describe('contact links', () => {
  it('builds tel: links from stored numbers', () => {
    expect(telUrl('076-555-0707')).toBe('tel:0765550707');
    expect(telUrl('+66 (2) 555 0101', '12')).toBe('tel:+6625550101,12');
    expect(telUrl('n/a')).toBeNull();
  });

  it('builds mailto: only for real addresses', () => {
    expect(mailtoUrl(' mali@andamancold.example ')).toBe('mailto:mali@andamancold.example');
    expect(mailtoUrl('mali@x.com?bcc=evil@x.com')).toBeNull();
    expect(mailtoUrl('javascript:alert(1)')).toBeNull();
  });
});
