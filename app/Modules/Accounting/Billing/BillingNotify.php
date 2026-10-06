<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Billing;

use App\Core\Support\DB;
use App\Core\Support\Lark;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Support\Log;

/**
 * One Lark message a day to the chosen people: every billing note whose remind date is today, with its details.
 * Settings (Accounting → Notifications): on/off, time of day, who. Sent by the scheduler tick (cron or the website), once per day.
 */
final class BillingNotify
{
    private const C = ErpSettings::CONN;

    public static function enabled(): bool { return ErpSettings::get('notify.enabled', '0') === '1'; }
    public static function time(): string { return (string) ErpSettings::get('notify.time', '08:30'); }
    /** @return int[] */
    public static function userIds(): array { return array_values(array_filter(array_map('intval', explode(',', (string) ErpSettings::get('notify.users', ''))))); }

    /** @return array<int, array> the notes to follow up today */
    public static function today(): array
    {
        return DB::select("SELECT b.*, (SELECT count(*) FROM billing_note_invoices i WHERE i.billing_id = b.id) AS invoice_count
                             FROM billing_notes b WHERE b.remind_date = (now() AT TIME ZONE 'Asia/Bangkok')::date AND NOT b.is_complete ORDER BY b.billing_number", [], self::C);
    }

    /** @return array{title: string, lines: string[]} */
    public static function message(array $notes): array
    {
        $lines = [];
        $sum = 0.0;
        foreach ($notes as $n) {
            $status = filter_var($n['is_complete'], FILTER_VALIDATE_BOOL) ? __('Complete') : (filter_var($n['is_paid'], FILTER_VALIDATE_BOOL) ? __('Paid') : __('Pending'));
            $lines[] = '• '.$n['billing_number'].' · '.$n['customer_code'].' '.$n['customer_name'].' · '.BillingService::symbol($n['currency_code']).number_format((float) $n['total_amount'], 2).' · '.(int) $n['invoice_count'].' '.__('invoices').' · '.$status;
            if ($n['currency_code'] === 'THB') { $sum += (float) $n['total_amount']; }
        }
        if ($notes) { $lines[] = __('Total (THB)').': ฿'.number_format($sum, 2); }

        return ['title' => __('Billing notes to follow up today (:n)', ['n' => count($notes)]).' · '.date('d/m/Y'), 'lines' => $lines ?: [__('No billing notes are due today.')]];
    }

    /** Send now. @return array{sent: int, failed: string[], notes: int} */
    public static function send(bool $evenIfEmpty = false): array
    {
        $notes = self::today();
        $out = ['sent' => 0, 'failed' => [], 'notes' => count($notes)];
        if (! $notes && ! $evenIfEmpty) { return $out; }
        $m = self::message($notes);
        $url = url('/accounting/billing');
        $mode = Lark::mode();
        if ($mode === 'webhook') {
            Lark::webhook($m['title'], $m['lines'], $url) ? $out['sent']++ : $out['failed'][] = 'webhook';
        } elseif ($mode === 'app') {
            $ids = self::userIds();
            $users = $ids ? DB::select('SELECT id, name, email FROM users WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') AND is_active AND deleted_at IS NULL', $ids) : [];
            foreach ($users as $u) { Lark::direct((string) $u['email'], $m['title'], $m['lines'], $url) ? $out['sent']++ : $out['failed'][] = (string) $u['email']; }
            if (! $users) { $out['failed'][] = __('no recipients chosen'); }
        } else {
            $out['failed'][] = __('Lark is switched off.');
        }
        Log::info('billing', 'Lark daily list: '.$out['notes'].' note(s), sent '.$out['sent'], ['failed' => $out['failed']]);

        return $out;
    }

    /** Called every minute by the scheduler: once a day, after the chosen time. */
    public static function tick(): void
    {
        if (! self::enabled()) { return; }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Bangkok'));
        if ($now->format('H:i') < self::time() || ErpSettings::get('notify.last') === $now->format('Y-m-d')) { return; }
        ErpSettings::put('notify.last', $now->format('Y-m-d'));                 // claim the day first: two processes must not both send
        try { self::send(); } catch (\Throwable $e) { Log::exception('billing', $e, 'daily Lark list failed'); }
    }
}
