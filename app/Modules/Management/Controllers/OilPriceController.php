<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\OilPrices;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Settings;
use App\Core\Support\ValidationException;

/**
 * Management → Oil prices: today's PTT prices, a chart of every price change, the change history and the sync log.
 * Management and administrators can sync now, load past days and change the morning sync times.
 */
class OilPriceController extends Controller
{
    public const RANGES = ['3m' => '3 months', '6m' => '6 months', '1y' => '1 year', 'all' => 'All'];

    public function index(): string
    {
        $this->authorize('management', 'view');
        $range = (string) Request::query('range', '6m');
        $range = isset(self::RANGES[$range]) ? $range : '6m';
        $fuel = (string) Request::query('fuel', '');
        $from = match ($range) { '3m' => date('Y-m-d', strtotime('-3 months')), '1y' => date('Y-m-d', strtotime('-1 year')), 'all' => '2000-01-01', default => date('Y-m-d', strtotime('-6 months')) };

        $products = array_column(DB::select('SELECT code, name_en, name_th, active FROM oil_products ORDER BY active DESC, sort, name_en'), null, 'code');
        // every price in the range, plus the price each fuel had when the range starts (so its line starts at the left edge)
        $rows = DB::select("SELECT product_code, price, effective_at FROM oil_prices WHERE effective_at >= ?::date
                UNION SELECT DISTINCT ON (product_code) product_code, price, ?::date FROM oil_prices WHERE effective_at < ?::date ORDER BY 3, 1", [$from, $from, $from]);
        $dates = array_values(array_unique(array_map(fn ($r) => substr((string) $r['effective_at'], 0, 10), $rows)));
        sort($dates);
        $series = [];
        foreach ($products as $code => $p) {
            $byDay = [];
            foreach ($rows as $r) {
                if ($r['product_code'] === $code) { $byDay[substr((string) $r['effective_at'], 0, 10)] = (float) $r['price']; }
            }
            if (! $byDay) { continue; }
            $vals = []; $last = null;
            foreach ($dates as $d) { $last = $byDay[$d] ?? $last; $vals[] = $last; }   // a price holds until it changes
            $series[] = ['code' => $code, 'label' => locale() === 'th' && $p['name_th'] ? $p['name_th'] : $p['name_en'], 'color' => OilPrices::color($code), 'data' => $vals, 'active' => (bool) $p['active']];
        }
        if ($dates && date('Y-m-d') > $dates[count($dates) - 1]) {   // carry the lines on to today
            $dates[] = date('Y-m-d');
            foreach ($series as &$s) { $s['data'][] = $s['data'][count($s['data']) - 1]; }
            unset($s);
        }

        $hp = []; $hw = 'TRUE';
        if ($fuel !== '' && isset($products[$fuel])) { $hw = 'o.product_code = ?'; $hp[] = $fuel; }
        $history = DB::select("SELECT o.product_code, o.price, o.effective_at, lag(o.price) OVER (PARTITION BY o.product_code ORDER BY o.effective_at) AS before
            FROM oil_prices o WHERE {$hw} ORDER BY o.effective_at DESC, o.product_code LIMIT 300", $hp);

        return view('management::oil-prices', [
            'title' => __('Oil prices'), 'oil' => OilPrices::current(), 'products' => $products, 'dates' => $dates, 'series' => $series, 'range' => $range, 'fuel' => $fuel,
            'history' => $history, 'syncs' => DB::select("SELECT * FROM oil_price_syncs WHERE source <> 'history' ORDER BY run_at DESC, id DESC LIMIT 15"),   // history loads are in the activity log
            'times' => OilPrices::times(), 'last' => OilPrices::lastSync(), 'due' => OilPrices::due(),
        ]);
    }

    public function sync(): never
    {
        $this->authorize('management', 'view');
        $u = $this->user();
        try {
            $r = OilPrices::sync('manual', null, $u->id, $u->name);
        } catch (\Throwable $e) {
            back('error', __('The prices could not be fetched from PTT: :m', ['m' => $e->getMessage()]));
        }
        Session::flash('success', $r['changed'] ? __(':n price change(s) saved.', ['n' => $r['changed']]) : __('Prices checked: nothing changed since the last update.'));
        redirect('/management/oil-prices');
    }

    /** Load past days from PTT (one request per day), to fill the history and the chart. */
    public function history(): never
    {
        $this->authorize('management', 'view');
        $days = (int) Request::input('days', 30);
        if (! in_array($days, [30, 90, 180, 365], true)) {
            throw new ValidationException(['days' => __('Choose how far back to load.')]);
        }
        @set_time_limit(600);
        session_write_close();
        $u = $this->user();
        $tz = new \DateTimeZone(OilPrices::TZ);
        $changed = $failed = 0;
        // every day up to 90 days back; further back every second day (prices rarely hold for less than two days)
        $step = $days <= 90 ? 1 : 2;
        $day = new \DateTimeImmutable('today', $tz);
        for ($i = $days; $i >= 0; $i--) {
            if ($i > 14 && $i % $step !== 0) { continue; }
            try { $changed += OilPrices::sync('history', $day->modify("-{$i} days"), $u->id, $u->name)['changed']; }
            catch (\Throwable) { $failed++; }
        }
        Activity::log('updated', 'oil_prices', null, 'Oil prices', ['Loaded :n days of oil price history', ['n' => $days]], ['changes' => $changed, 'failed' => $failed], null, notify: false, module: 'management');
        Session::flash($failed ? 'error' : 'success', __('History loaded: :c price change(s) found.', ['c' => $changed]).($failed ? ' '.__(':n day(s) could not be fetched.', ['n' => $failed]) : ''));
        redirect('/management/oil-prices');
    }

    public function times(): never
    {
        $this->authorize('management', 'view');
        $raw = array_filter(array_map('trim', (array) Request::input('times', [])), 'strlen');
        $ok = [];
        foreach ($raw as $t) {
            if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $t, $m)) {
                throw new ValidationException(['times' => __('Use times like 05:10.')]);
            }
            $ok[] = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        $ok = array_values(array_unique($ok));
        sort($ok);
        if (! $ok || count($ok) > 8) {
            throw new ValidationException(['times' => __('Set between one and eight sync times.')]);
        }
        $before = implode(', ', OilPrices::times());
        Settings::put('oil_sync_times', implode(',', $ok));
        Activity::log('updated', 'oil_prices', null, 'Oil prices', ['Changed the oil price sync times to :t', ['t' => implode(', ', $ok)]], ['changes' => ['times' => ['from' => $before, 'to' => implode(', ', $ok)]]], null, notify: false, module: 'management');
        Session::flash('success', __('Sync times saved: :t (Bangkok time).', ['t' => implode(', ', $ok)]));
        redirect('/management/oil-prices');
    }
}
