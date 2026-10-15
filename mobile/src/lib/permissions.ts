import type { Action, ModuleKey, Profile } from '@/api/types';

/** Same answer as the website's can($resource, $action), from /me → permissions. */
export function can(profile: Profile | null | undefined, module: ModuleKey, resource: string, action: Action = 'view'): boolean {
  if (!profile || !profile.modules.includes(module)) return false;
  return profile.permissions[module]?.[resource]?.includes(action) ?? false;
}

export function hasModule(profile: Profile | null | undefined, module: ModuleKey): boolean {
  return !!profile && profile.modules.includes(module);
}
