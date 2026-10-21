<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Settings;
use App\Core\Support\ValidationException;

/**
 * Management → Power BI → Overview: a Power BI report shown full page in an iframe.
 * An administrator pastes the report's embed code (or its link) on the settings page; only the https link to a
 * Power BI host is kept, never the pasted HTML, so nothing else can be put on the page.
 */
class PowerBiController extends Controller
{
    private const KEY = 'powerbi_overview_url';

    public function index(): string
    {
        $this->authorize('management', 'view');

        return view('management::powerbi', ['title' => __('Power BI overview'), 'url' => (string) Settings::get(self::KEY, ''), 'isAdmin' => $this->user()->isAdmin()]);
    }

    public function edit(): string
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can change the Power BI report.'));

        return view('management::powerbi-settings', ['title' => __('Power BI settings'), 'url' => (string) Settings::get(self::KEY, '')]);
    }

    public function save(): never
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can change the Power BI report.'));
        $before = (string) Settings::get(self::KEY, '');
        $url = self::link((string) Request::input('embed', ''));
        Settings::put(self::KEY, $url !== '' ? $url : null);
        Activity::log('updated', 'powerbi', null, 'Power BI overview', [$url !== '' ? 'Set the Power BI overview report' : 'Removed the Power BI overview report', []],
            ['changes' => ['report' => ['from' => $before !== '' ? parse_url($before, PHP_URL_HOST) : '—', 'to' => $url !== '' ? parse_url($url, PHP_URL_HOST) : '—']]], null, notify: false, module: 'management');
        Session::flash('success', $url !== '' ? __('Power BI report saved.') : __('Power BI report removed.'));
        redirect($url !== '' ? '/management/powerbi' : '/management/powerbi/settings');
    }

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
