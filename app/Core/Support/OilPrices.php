<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Daily oil prices from PTT OR (GetOilPrice SOAP service).
 *  - sync(): asks PTT for one day's prices (English and Thai names) and stores a price only when it differs from the
 *    last stored price of that fuel. Every run is written to oil_price_syncs.
 *  - Scheduled every morning at the times in setting 'oil_sync_times' (Bangkok time, default 05:10, 06:10, 07:00):
 *    bin/oil-prices.php from cron, and as a fallback the once-a-minute tick of signed-in pages.
 * Shown on My workspace (current prices) and in Management → Oil prices (history, chart, sync).
 */
final class OilPrices
{
    private const URL = 'https://orapiweb.pttor.com/oilservice/OilPrice.asmx';
    public const TZ = 'Asia/Bangkok';
    public const DEFAULT_TIMES = '05:10,06:10,07:00';

    /** Brand-like colours for the tiles and chart lines, matched on the English name. */
    public static function color(string $code): string
    {
        return match (true) {
            str_contains($code, 'premium') || str_contains($code, 'super_power_diesel') => '#475569',
            str_contains($code, 'x99')        => '#0f766e',
            str_contains($code, 'gsh95')      => '#7c3aed',
            str_contains($code, 'b20')        => '#dc2626',
            str_contains($code, 'b7')         => '#1d4ed8',
            str_contains($code, 'diesel')     => '#1e3a8a',
            str_contains($code, 'e85')        => '#c026d3',
            str_contains($code, 'e20')        => '#65a30d',
            str_contains($code, 'gasohol_91') => '#16a34a',
            str_contains($code, 'gasohol_95') => '#ea580c',
            str_contains($code, 'gasoline')   => '#f59e0b',
            str_contains($code, 'ngv')        => '#0284c7',
            default                           => '#6b7280',
        };
    }

    public static function code(string $nameEn): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($nameEn)), '_') ?: 'fuel';
    }

    /* ----------------------------------------------------------- the service */

    /**
     * One day's prices from PTT. @return list<array{name: string, price: float, date: string}>  date = Y-m-d H:i:s Bangkok
     * @throws \RuntimeException when PTT cannot be reached or answers something unexpected
     */
    public static function fetch(\DateTimeInterface $day, string $lang = 'en'): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body><GetOilPrice xmlns="http://www.pttor.com"><Language>'.($lang === 'th' ? 'th' : 'en').'</Language>'
            .'<DD>'.(int) $day->format('j').'</DD><MM>'.(int) $day->format('n').'</MM><YYYY>'.(int) $day->format('Y').'</YYYY></GetOilPrice></soap:Body></soap:Envelope>';
        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "http://www.pttor.com/GetOilPrice"'],
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || $code !== 200) {
            throw new \RuntimeException('PTT oil price service did not answer'.($err ? ': '.$err : ' (HTTP '.$code.')'));
        }
        if (! preg_match('#<GetOilPriceResult>(.*)</GetOilPriceResult>#s', (string) $res, $m)) {
            throw new \RuntimeException('Unexpected answer from the PTT oil price service.');
        }
        $inner = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        preg_match_all('#<FUEL>\s*<PRICE_DATE>(.*?)</PRICE_DATE>\s*<PRODUCT>(.*?)</PRODUCT>\s*<PRICE>(.*?)</PRICE>\s*</FUEL>#s', $inner, $fs, PREG_SET_ORDER);
        $out = [];
        foreach ($fs as $f) {
            $when = \DateTimeImmutable::createFromFormat('m/d/Y g:i:s A', trim($f[1]), new \DateTimeZone(self::TZ));
            $price = trim($f[3]);
            if ($when && is_numeric($price) && (float) $price > 0 && trim($f[2]) !== '') {
                $out[] = ['name' => trim($f[2]), 'price' => round((float) $price, 2), 'date' => $when->format('Y-m-d H:i:s')];
            }
        }

        return $out;
    }

    /**
     * Fetch one day and keep the changed prices.
     * @return array{products: int, changed: int, price_date: ?string}
     */
    public static function sync(string $trigger = 'manual', ?\DateTimeInterface $day = null, ?int $userId = null, ?string $userName = null): array
    {
        $day ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $start = microtime(true);
        try {
            $en = self::fetch($day, 'en');
            if (! $en) {
                throw new \RuntimeException('PTT returned no prices for '.$day->format('Y-m-d').'.');
            }
            $th = [];                                    // Thai names are a nice-to-have; loading history skips them (the next sync adds them)
            if ($trigger !== 'history') {
                try { $th = self::fetch($day, 'th'); } catch (\Throwable) { $th = []; }
            }
            $changed = 0;
            $latestDate = null;
            foreach ($en as $i => $f) {
                $code = self::code($f['name']);
                $nameTh = isset($th[$i]) && abs($th[$i]['price'] - $f['price']) < 0.001 ? $th[$i]['name'] : null;   // same list, same order
                DB::exec('INSERT INTO oil_products (code, name_en, name_th, sort, active, last_seen) VALUES (?, ?, ?, ?, TRUE, now())
                          ON CONFLICT (code) DO UPDATE SET name_en = EXCLUDED.name_en, name_th = COALESCE(EXCLUDED.name_th, oil_products.name_th), last_seen = now(),
                              sort = CASE WHEN ? THEN EXCLUDED.sort ELSE oil_products.sort END, active = CASE WHEN ? THEN TRUE ELSE oil_products.active END',
                    [$code, $f['name'], $nameTh, $i, $trigger !== 'history', $trigger !== 'history']);
                // the last price this fuel had on or before this date: store only when it differs
                $prev = DB::first('SELECT price, effective_at FROM oil_prices WHERE product_code = ? AND effective_at <= ? ORDER BY effective_at DESC LIMIT 1', [$code, $f['date']]);
                $latestDate = max($latestDate ?? $f['date'], $f['date']);
                if ($prev && abs((float) $prev['price'] - $f['price']) < 0.001) {
                    continue;
                }
                $n = DB::exec('INSERT INTO oil_prices (product_code, price, effective_at) VALUES (?, ?, ?) ON CONFLICT (product_code, effective_at) DO NOTHING', [$code, $f['price'], $f['date']]);
                $changed += $n > 0 ? 1 : 0;
            }
            if ($changed) {   // loading older days can leave the same price twice in a row: keep only real changes
                DB::exec('DELETE FROM oil_prices o USING (SELECT id, price, lag(price) OVER (PARTITION BY product_code ORDER BY effective_at) AS prev FROM oil_prices) x
                          WHERE o.id = x.id AND x.prev = x.price');
            }
            if ($trigger !== 'history') {   // fuels PTT no longer lists drop off the dashboard (their history stays)
                $codes = array_map(fn ($f) => self::code($f['name']), $en);
                DB::exec('UPDATE oil_products SET active = FALSE WHERE code NOT IN ('.implode(',', array_fill(0, count($codes), '?')).')', $codes);
            }
            self::log($trigger, $day, $userId, $userName, 'ok', count($en), $changed, $latestDate, $changed ? null : 'No price changed.', $start);

            return ['products' => count($en), 'changed' => $changed, 'price_date' => $latestDate];
        } catch (\Throwable $e) {
            self::log($trigger, $day, $userId, $userName, 'failed', 0, 0, null, $e->getMessage(), $start);
            throw $e;
        }
    }

    private static function log(string $trigger, \DateTimeInterface $day, ?int $userId, ?string $userName, string $status, int $products, int $changed, ?string $priceDate, ?string $msg, float $start): void
    {
        DB::insert('oil_price_syncs', ['source' => $trigger, 'for_date' => $day->format('Y-m-d'), 'user_id' => $userId, 'user_name' => $userName, 'status' => $status,
            'products' => $products, 'changed' => $changed, 'price_date' => $priceDate, 'message' => $msg ? mb_substr($msg, 0, 500) : null,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000)]);
    }

    /* -------------------------------------------------------------- schedule */

    /** @return list<string> "HH:MM", sorted */
    public static function times(): array
    {
        $out = [];
        foreach (explode(',', (string) Settings::get('oil_sync_times', self::DEFAULT_TIMES)) as $t) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($t), $m)) {
                $out[] = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /** The latest scheduled time today that has passed, if it has not been run yet (a failure is retried after 10 minutes). */
    public static function due(?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $slot = null;
        foreach (self::times() as $t) {
            if ($now->format('H:i') >= $t) {
                $slot = $t;
            }
        }
        if ($slot === null) {
            return null;
        }
        $since = $now->format('Y-m-d').' '.$slot.':00';
        $last = DB::first("SELECT status, run_at FROM oil_price_syncs WHERE source IN ('schedule', 'manual') AND run_at >= (?::timestamp AT TIME ZONE '".self::TZ."')
                           ORDER BY run_at DESC LIMIT 1", [$since]);
        if ($last && ($last['status'] === 'ok' || strtotime((string) $last['run_at']) > time() - 600)) {
            return null;
        }

        return $slot;
    }

    /** Run the scheduled sync when one is due. Cheap when nothing is due; one runner at a time. */
    public static function tick(): ?array
    {
        if (self::due() === null) {
            return null;
        }
        $lock = @fopen(BASE_PATH.'/storage/cache/oil-prices.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        try {
            return self::due() === null ? null : self::sync('schedule');       // checked again inside the lock
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /* ------------------------------------------------------------------ data */

    /**
     * The current price of every fuel PTT still lists, with the price before it.
     * @return list<array{code, name_en, name_th, price, effective_at, previous, change, color}>
     */
    public static function current(): array
    {
        $rows = DB::select('SELECT p.code, p.name_en, p.name_th,
                (SELECT row_to_json(x) FROM (SELECT price, effective_at FROM oil_prices o WHERE o.product_code = p.code ORDER BY effective_at DESC LIMIT 1) x) AS cur,
                (SELECT price FROM oil_prices o WHERE o.product_code = p.code ORDER BY effective_at DESC OFFSET 1 LIMIT 1) AS previous
            FROM oil_products p WHERE p.active ORDER BY p.sort, p.name_en');
        $out = [];
        foreach ($rows as $r) {
            $cur = json_decode((string) $r['cur'], true);
            if (! $cur) {
                continue;
            }
            $prev = $r['previous'] !== null ? (float) $r['previous'] : null;
            $out[] = ['code' => $r['code'], 'name_en' => $r['name_en'], 'name_th' => $r['name_th'], 'price' => (float) $cur['price'], 'effective_at' => $cur['effective_at'],
                'previous' => $prev, 'change' => $prev !== null ? round((float) $cur['price'] - $prev, 2) : null, 'color' => self::color($r['code'])];
        }

        return $out;
    }

    public static function lastSync(): ?array
    {
        return DB::first("SELECT * FROM oil_price_syncs WHERE status = 'ok' AND source <> 'history' ORDER BY run_at DESC LIMIT 1");
    }
}
