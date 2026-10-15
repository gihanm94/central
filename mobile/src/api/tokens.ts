/** A device sign-in: what POST /auth/login and /auth/refresh hand out. */
export interface TokenPair {
  accessToken: string;
  refreshToken: string;
  /** ms since epoch when the access token stops working (15 minutes by default). */
  accessExpiresAt: number;
  /** ms since epoch when the refresh token stops working. */
  refreshExpiresAt: number;
  /** api_devices.id of this sign-in. */
  deviceId: number;
}

/** Where the token pair lives. The app uses the Keychain / Keystore (see security/secureTokenStore.ts). */
export interface TokenStore {
  load(): Promise<TokenPair | null>;
  save(pair: TokenPair): Promise<void>;
  clear(): Promise<void>;
}

/** The answer of /auth/login and /auth/refresh. */
export interface TokenResponse {
  token_type: 'Bearer';
  access_token: string;
  expires_in: number;
  refresh_token: string;
  refresh_expires_in: number;
  device_id: number;
}

export function pairFromResponse(r: TokenResponse, now: number = Date.now()): TokenPair {
  return {
    accessToken: r.access_token,
    refreshToken: r.refresh_token,
    accessExpiresAt: now + r.expires_in * 1000,
    refreshExpiresAt: now + r.refresh_expires_in * 1000,
    deviceId: r.device_id,
  };
}

/** Plain JSON round trip with shape checks (a corrupt entry is treated as signed out). */
export function serializePair(p: TokenPair): string {
  return JSON.stringify(p);
}

export function deserializePair(raw: string | null): TokenPair | null {
  if (!raw) return null;
  try {
    const p = JSON.parse(raw) as Partial<TokenPair>;
    if (
      typeof p.accessToken === 'string' &&
      typeof p.refreshToken === 'string' &&
      typeof p.accessExpiresAt === 'number' &&
      typeof p.refreshExpiresAt === 'number' &&
      typeof p.deviceId === 'number'
    ) {
      return p as TokenPair;
    }
  } catch {
    // fall through
  }
  return null;
}

/** For tests and the Node flow script. */
export class MemoryTokenStore implements TokenStore {
  pair: TokenPair | null = null;
  saves = 0;

  constructor(initial: TokenPair | null = null) {
    this.pair = initial;
  }

  async load(): Promise<TokenPair | null> {
    return this.pair;
  }

  async save(pair: TokenPair): Promise<void> {
    this.saves++;
    this.pair = pair;
  }

  async clear(): Promise<void> {
    this.pair = null;
  }
}
