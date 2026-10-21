<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * The Power BI report shown on Management → Power BI → Overview. Set by an administrator under Company settings → Power BI;
 * only the https link to a Power BI host is stored, never pasted HTML.
 */
final class PowerBi
{
    public const KEY = 'powerbi_overview_url';

    public static function url(): string { return (string) Settings::get(self::KEY, ''); }

    /** The report link from pasted embed code (<iframe src="…">) or a plain link; '' clears it. Only https Power BI hosts. */
    public static function link(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $input, $m)) {
            $input = $m[2];
        } elseif (str_contains($input, '<')) {
            throw new ValidationException(['embed' => __('Paste the Power BI embed code (<iframe …>) or the report link.')]);
        }
        $url  = trim(html_entity_decode($input, ENT_QUOTES | ENT_HTML5));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! preg_match('/(^|\.)(powerbi\.com|powerbigov\.us|powerbi\.cn|analysis\.windows\.net)$/', $host) || preg_match('/[\s"\'<>]/', $url)) {
            throw new ValidationException(['embed' => __('Use a Power BI report link (https://app.powerbi.com/…).')]);
        }

        return $url;
    }
}
