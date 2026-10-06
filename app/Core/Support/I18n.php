<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Translations. Keys are the English text, so English needs no file entries:
 *   __('Sign in')                         → "เข้าสู่ระบบ" in Thai
 *   __('Hello :name', ['name' => 'Gihan'])
 * Missing Thai lines fall back to English, so nothing ever shows blank.
 */
final class I18n
{
    private static string $locale = 'en';
    private static array $lines = [];

    public static function available(): array { return (array) config('core.locales', ['en' => 'English']); }

    public static function locale(): string { return self::$locale; }

    public static function set(?string $locale): void
    {
        self::$locale = $locale && isset(self::available()[$locale]) ? $locale : 'en';
    }

    /** User preference → language cookie (guests) → company default → English. */
    public static function resolve(?string $userLocale): string
    {
        foreach ([$userLocale, $_COOKIE['lang'] ?? null, Settings::get('default_locale'), 'en'] as $candidate) {
            if ($candidate && isset(self::available()[$candidate])) {
                return $candidate;
            }
        }

        return 'en';
    }

    public static function translate(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= self::$locale;
        $line = self::lines($locale)[$key] ?? $key;
        foreach ($params as $k => $v) {
            $line = str_replace(':'.$k, (string) $v, $line);
        }

        return $line;
    }

    public static function has(string $key, ?string $locale = null): bool
    {
        return isset(self::lines($locale ?? self::$locale)[$key]);
    }

    /** Run something (e.g. build an e-mail) in another person's language. */
    public static function with(?string $locale, callable $fn): mixed
    {
        $previous = self::$locale;
        self::set($locale ?: (Settings::get('default_locale') ?: 'en'));
        try {
            return $fn();
        } finally {
            self::$locale = $previous;
        }
    }

    private static function lines(string $locale): array
    {
        if (! isset(self::$lines[$locale])) {
            $file = BASE_PATH.'/lang/'.basename($locale).'.php';
            self::$lines[$locale] = is_file($file) ? (array) require $file : [];
        }

        return self::$lines[$locale];
    }
}
