<?php
declare(strict_types=1);

namespace App\Core\Support;

/** "Chrome on macOS · Desktop" from a user-agent string. */
final class Device
{
    public static function label(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') { return __('Unknown device'); }
        $browser = match (true) {
            (bool) preg_match('~Edg(e|A|iOS)?/~', $ua) => 'Edge', (bool) preg_match('~OPR/|Opera~', $ua) => 'Opera', (bool) preg_match('~Firefox/|FxiOS~', $ua) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS~', $ua) => 'Chrome', (bool) preg_match('~Safari/~', $ua) => 'Safari', (bool) preg_match('~curl|python|Postman~i', $ua) => 'Script', default => __('Browser'),
        };
        $os = match (true) {
            (bool) preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS', (bool) preg_match('~Android~', $ua) => 'Android', (bool) preg_match('~Windows~', $ua) => 'Windows',
            (bool) preg_match('~Mac OS X|Macintosh~', $ua) => 'macOS', (bool) preg_match('~CrOS~', $ua) => 'ChromeOS', (bool) preg_match('~Linux~', $ua) => 'Linux', default => '',
        };
        $type = preg_match('~iPad|Tablet~', $ua) ? __('Tablet') : (preg_match('~Mobile|iPhone|Android~', $ua) ? __('Mobile') : __('Desktop'));

        return $browser.($os !== '' ? ' · '.$os : '').' · '.$type;
    }
}
