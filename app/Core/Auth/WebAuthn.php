<?php
declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;

/**
 * Passkeys (WebAuthn) with no external library.
 * Registration uses the browser's response.getPublicKey() (SPKI), so no CBOR parsing.
 * Sign-in verifies the signature with OpenSSL (ES256 / RS256) or libsodium (Ed25519).
 */
final class WebAuthn
{
    public static function rpId(): string
    {
        return config('security.passkey_rp_id') ?: Request::host();
    }

    public static function registrationOptions(CurrentUser $user): array
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_register'] = self::b64u($challenge);

        return [
            'challenge' => self::b64u($challenge),
            'rp'        => ['name' => setting('company_name', config('app.name')), 'id' => self::rpId()],
            'user'      => [
                'id'          => self::b64u(hash('sha256', config('app.key').'|'.$user->id, true)),
                'name'        => $user->email,
                'displayName' => $user->name,
            ],
            'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -8], ['type' => 'public-key', 'alg' => -257]],
            'timeout'                => 60000,
            'attestation'            => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'preferred'],
            'excludeCredentials'     => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id],
                array_column(DB::select('SELECT credential_id FROM passkeys WHERE user_id = ?', [$user->id]), 'credential_id')),
        ];
    }

    public static function register(CurrentUser $user, array $d): int
    {
        $expected = $_SESSION['webauthn_register'] ?? null;
        unset($_SESSION['webauthn_register']);

        self::checkClient((string) ($d['clientDataJSON'] ?? ''), 'webauthn.create', $expected);
        $auth = self::b64uDecode((string) ($d['authenticatorData'] ?? ''));
        self::checkAuthData($auth);

        $der = self::b64uDecode((string) ($d['publicKey'] ?? ''));
        if ($der === '') {
            self::fail('This browser cannot create passkeys. Update it or try Chrome, Edge or Safari.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $alg = (int) ($d['publicKeyAlgorithm'] ?? 0);
        if (! in_array($alg, [-7, -8, -257], true) || ($alg !== -8 && ! openssl_pkey_get_public($pem))) {
            self::fail('Unsupported passkey type.');
        }
        $rawId = (string) ($d['rawId'] ?? '');
        if ($rawId === '' || strlen($rawId) > 500) {
            self::fail('Invalid passkey id.');
        }

        return (int) DB::insert('passkeys', [
            'user_id'       => $user->id,
            'name'          => mb_substr(trim((string) ($d['name'] ?? '')) ?: 'Passkey', 0, 80),
            'credential_id' => $rawId,
            'public_key'    => $pem,
            'algorithm'     => $alg,
            'sign_count'    => unpack('N', substr($auth, 33, 4))[1],
        ]);
    }

    public static function loginOptions(): array
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_login'] = self::b64u($challenge);

        return ['challenge' => self::b64u($challenge), 'rpId' => self::rpId(), 'timeout' => 60000, 'userVerification' => 'preferred', 'allowCredentials' => []];
    }

    /** Returns the user id the passkey belongs to. */
    public static function verifyLogin(array $d): int
    {
        $expected = $_SESSION['webauthn_login'] ?? null;
        unset($_SESSION['webauthn_login']);

        $key = DB::first('SELECT * FROM passkeys WHERE credential_id = ?', [(string) ($d['rawId'] ?? '')]);
        if (! $key) {
            self::fail('This passkey is not registered. Sign in with your password, then add it from your profile.');
        }

        $clientRaw = self::b64uDecode((string) ($d['clientDataJSON'] ?? ''));
        self::checkClient((string) $d['clientDataJSON'], 'webauthn.get', $expected);
        $auth = self::b64uDecode((string) ($d['authenticatorData'] ?? ''));
        self::checkAuthData($auth);

        $signed = $auth.hash('sha256', $clientRaw, true);
        $sig    = self::b64uDecode((string) ($d['signature'] ?? ''));
        $ok     = (int) $key['algorithm'] === -8
            ? self::verifyEd25519($signed, $sig, $key['public_key'])
            : openssl_verify($signed, $sig, $key['public_key'], OPENSSL_ALGO_SHA256) === 1;
        if (! $ok) {
            self::fail('The passkey signature could not be verified.');
        }

        $count = unpack('N', substr($auth, 33, 4))[1];
        if ($count !== 0 && (int) $key['sign_count'] !== 0 && $count <= (int) $key['sign_count']) {
            self::fail('This passkey looks cloned and was blocked.');
        }
        DB::exec('UPDATE passkeys SET sign_count = ?, last_used_at = now() WHERE id = ?', [$count, $key['id']]);

        return (int) $key['user_id'];
    }

    private static function checkClient(string $b64, string $type, ?string $challenge): void
    {
        $c = json_decode(self::b64uDecode($b64), true);
        if (! is_array($c) || ($c['type'] ?? '') !== $type) {
            self::fail('Unexpected passkey response.');
        }
        if (! $challenge || ! hash_equals($challenge, (string) ($c['challenge'] ?? ''))) {
            self::fail('The passkey request expired. Try again.');
        }
        if (rtrim((string) ($c['origin'] ?? ''), '/') !== Request::origin()) {
            self::fail('Passkey origin does not match this site.');
        }
    }

    private static function checkAuthData(string $auth): void
    {
        if (strlen($auth) < 37 || ! hash_equals(hash('sha256', self::rpId(), true), substr($auth, 0, 32))) {
            self::fail('This passkey was made for a different site.');
        }
        if ((ord($auth[32]) & 0x01) === 0) {
            self::fail('Touch your security key or confirm on your device.');
        }
    }

    private static function verifyEd25519(string $data, string $sig, string $pem): bool
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));

        return function_exists('sodium_crypto_sign_verify_detached') && strlen($sig) === 64
            && sodium_crypto_sign_verify_detached($sig, $data, substr($der, -32));
    }

    private static function fail(string $msg): never { throw new ValidationException(['passkey' => $msg]); }

    public static function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
