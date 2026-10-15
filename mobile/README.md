# Acme mobile app

iOS and Android app for the Acme company system. It has three modules: **CRM**, **Machine Checklist** and **Accounting** (read only).
It only talks to the backend's token API (`/api/v1`, see [`../docs/API.md`](../docs/API.md)) and does only what the
website allows the person to do.

| | |
|---|---|
| Stack | React Native 0.86 + Expo SDK 57, TypeScript (strict), Expo Router, TanStack Query |
| Device | expo-secure-store (tokens), expo-camera (QR + photos), expo-image-manipulator (photo < 2 MB), expo-file-system + expo-sharing (PDFs), expo-local-authentication (app lock), expo-screen-capture (app switcher) |
| Languages | English and Thai (`src/i18n`); after sign-in the server answers in the person's own language |
| Tests | Jest (`jest-expo`) + React Native Testing Library, plus `scripts/flows.ts` against a real backend |

## Folder structure

```
mobile/
  app.config.ts          variants (development / preview / production), permissions, build-time server
  eas.json               EAS build profiles
  scripts/flows.ts       main flows against a running backend (npm run flows)
  scripts/dev/*.sql      demo machine + Inet data for a local backend
  src/
    api/                 ApiClient (tokens, single-flight refresh, errors), typed models, every endpoint
    app/                 screens (Expo Router: one file per route)
      sign-in.tsx, server.tsx
      (app)/index.tsx    home: only the modules in /me
      (app)/machines/    scan, search, machine, today's check, my checks, approvals, due
      (app)/crm/         stats + search, leads, contacts, opportunities, activities (+ log / edit), tasks
      (app)/accounting/  overview, customers, products, orders, invoices, sellers, Inets, billing notes
      (app)/profile/     devices, notifications, settings
    components/          UI kit, paged lists, pickers, date/time field
    features/            module logic (check form rules, activity form, labels, PDF button)
    i18n/                en.ts (source of keys), th.ts (checked by TypeScript)
    lib/                 formatting, server URL rules, QR checks, photo compression, PDFs, permissions
    security/            secure storage, install id, app lock, privacy shield
    state/               session (sign-in / sign-out / profile), settings, module health
```

## Setup

Node 20+ (22 recommended).

```bash
cd mobile
npm install            # .npmrc sets legacy-peer-deps: Expo's optional peers conflict under npm's strict resolver
npm run typecheck      # tsc --noEmit
npm test               # Jest: API client, refresh queue, error mapping, multipart, forms, screens, translations
npm run check          # both
```

Add native packages with `npx expo install <package>` so versions match the SDK.

## Configuration

**Server.** People type the server address on the sign-in screen ("Change"). `erp.example.com`,
`https://erp.example.com/` and `https://erp.example.com/api/v1` all work. The build-time default comes from
`EXPO_PUBLIC_API_URL` (set per profile in `eas.json`). The address can only be changed while signed out.

**HTTPS.** Only `https://` addresses are accepted. Plain `http://` is allowed **only in the development variant**
(`APP_VARIANT=development`), and only for `localhost`, `127.0.0.1`, `[::1]` and `10.0.2.2` (the Android emulator's
name for your computer). That variant also turns on iOS `NSAllowsLocalNetworking` and Android cleartext traffic;
preview and production builds have neither.

| Variable | Where | Meaning |
|---|---|---|
| `APP_VARIANT` | build env | `development`, `preview` or `production` (default). Changes app name, bundle id (`com.acmeinter.mobile[.dev/.preview]`) and the HTTP rule above |
| `EXPO_PUBLIC_API_URL` | build env | default server address |

Change `ios.bundleIdentifier` / `android.package` in `app.config.ts` to your own before the first store build.

## Run against a local backend

1. Start the PHP backend (see the main [README](../README.md)). On a fresh install also:
   ```bash
   # API access for every role (the installer gives it to nobody)
   psql -h 127.0.0.1 -U acme -d user_db -c "INSERT INTO role_permissions (role_id, permission_id, can_view)
     SELECT r.id, p.id, true FROM roles r, permissions p WHERE p.key = 'api_access'
     ON CONFLICT (role_id, permission_id) DO UPDATE SET can_view = true"
   # Known backend issue: Machine Checklist permissions are missing on a fresh install
   psql -h 127.0.0.1 -U acme -d user_db -f ../database/sql/migrations/core/2026_10_17_001_machines_permissions.sql
   # Demo machines (the installer has none) and one Inet with a stored PDF
   psql -h 127.0.0.1 -U acme -d machine_db -f scripts/dev/seed-machines.sql
   psql -h 127.0.0.1 -U acme -d account_db -f scripts/dev/seed-accounting.sql
   mkdir -p ../storage/inet/demo && cp <any.pdf> ../storage/inet/demo/1001_388.pdf
   ```
   `config.php` must have `app.env = local` for plain HTTP. Demo users: `admin@`, `management@`, `manager@`,
   `member@acmeinter.com`, password `Password@123`. The demo QR code text is `{"code":"OPS-CN01-0001"}`.

2. Check the API with the app's own client (no phone needed):
   ```bash
   npm run flows                                  # http://127.0.0.1:8000
   API_URL=https://staging.example.com MEMBER_EMAIL=… MEMBER_PASSWORD=… npm run flows
   ```
   It signs in, reads /me, lists leads, logs and edits a call, checks the 422 field errors, scans a QR code,
   submits a check with a photo (multipart), fires parallel requests with an expired token (exactly one refresh
   must happen), signs out, then as an accounting user downloads an Inet PDF and checks `pdf_not_available`.

3. Run the app. Native modules (secure store, camera, biometrics) need a **development build**, not Expo Go:
   ```bash
   APP_VARIANT=development EXPO_PUBLIC_API_URL=http://10.0.2.2:8000 npx expo run:android   # emulator
   APP_VARIANT=development EXPO_PUBLIC_API_URL=http://localhost:8000 npx expo run:ios      # simulator (macOS)
   ```
   On a real phone, the backend must be reachable over HTTPS (for example a staging server or a tunnel); a
   development build also accepts the computer's `localhost` only through `adb reverse tcp:8000 tcp:8000` on Android.

## Run against production

Use a preview or production build and type the server's `https://` address (or bake it in with
`EXPO_PUBLIC_API_URL`). On the server: `api.enabled = true`, HTTPS in front of PHP, and give the
**Mobile app & API** permission to the roles that may use the app (Administration → Roles & access).

## Build for iOS and Android (EAS)

```bash
npm install -g eas-cli        # or: npx eas-cli@latest …
eas login
eas init                      # once: links the project to your Expo account (writes the project id)
eas build --profile development --platform android   # APK for emulators/devices, http to local machine allowed
eas build --profile preview --platform all           # internal testers (HTTPS only)
eas build --profile production --platform all        # store builds; set EXPO_PUBLIC_API_URL in eas.json first
eas submit --profile production --platform ios       # or android
```

EAS manages signing (Apple distribution certificate and provisioning profile, Android keystore) the first time
you build. Store builds need an Apple Developer account and a Google Play developer account.
To check that the JavaScript bundles build without EAS: `npm run export` (`expo export` for iOS and Android).

## Security

| Requirement | How |
|---|---|
| Token storage | Keychain / Keystore only (`WHEN_UNLOCKED_THIS_DEVICE_ONLY`: never in backups). Settings and the cached profile are there too. Nothing goes to AsyncStorage. |
| No secrets in logs | The client logs (development builds only) method, path without query, status and request id. Never bodies, headers, tokens or passwords. |
| Token refresh | On `401 token_expired` the client refreshes once and retries once. Only one refresh runs at a time; other requests wait for it (a refresh token used twice signs the device out). The new pair is stored before it is used. A refresh that finishes after sign-out is dropped. |
| Sign-in ended | `invalid_token`, `session_ended`, `no_api_access`, `unauthenticated` (or a refused refresh) clear the tokens and everything cached, and show the sign-in screen with the server's message. |
| 429 | Retry-After is respected: requests are held (≤ 5 s) or fail fast until the window passes; `locked` shows a countdown on sign-in. |
| 503 | `module_unavailable` marks only that module (banner + "Unavailable" on home); the rest keeps working. |
| App lock | Face ID / fingerprint / phone passcode on start, after time in the background and after inactivity (Profile → Settings, on by default, 0/1/5/15 min). Nothing is rendered behind the lock. |
| App switcher | iOS: system blur + an opaque cover while inactive. Android: `FLAG_SECURE` (blank thumbnail; it also blocks screenshots). |
| Sign-out | Server sign-out, then tokens, profile, query cache, downloaded PDFs, photos and the image cache are deleted. |
| QR codes | At most 500 characters, no control characters, sent to `POST /machines/scan` as text. The app never opens a URL from a QR code. |
| Links | Only `tel:` and `mailto:` links the app builds itself. PDF URLs from the server are reduced to their API path and always fetched from the configured server, so the token never goes to another host. |
| Accounting | Read only: there is no generate, send or sync anywhere. |

## Backend changes made for the app

- `/auth/refresh` answered `401 session_expired` when a sign-in had run out, a code missing from the documented
  list. It now answers `session_ended` like every other ended sign-in (`app/Core/Api/Tokens.php`, `docs/API.md`).
