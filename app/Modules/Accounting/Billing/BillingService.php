<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Billing;

use App\Core\Support\DB;
use App\Core\Support\View;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Inet\Company;
use App\Modules\Accounting\Inet\PdfRenderer;
use App\Modules\Accounting\Support\Log;

/**
 * Billing notes (ใบวางบิล): pick a customer, tick its unbilled invoices, give an address and dates → a numbered note (BI + yy + mm + 001, 002 …)
 * with its PDF. Port of the old BillingNoteService; the invoices come from the prepared erp_documents (+ the ERP receivable when there is one).
 */
final class BillingService
{
    private const C = ErpSettings::CONN;
    private const SYMBOL = ['THB' => '฿', 'USD' => '$', 'SGD' => '$', 'NZD' => '$', 'CAD' => '$', 'AUD' => '$', 'EUR' => '€', 'GBP' => '£', 'RUB' => '₽', 'TWD' => 'NT$', 'JPY' => '¥', 'CNY' => '¥'];
    private const BANKS = [
        'th' => [['ธนาคาร กสิกรไทย', 'สาขาอ่อนนุช 39', 'ออมทรัพย์', '746-2-14207-7'], ['ธนาคาร ไทยพาณิชย์', 'สาขาบางจาก', 'ออมทรัพย์', '089-2-17954-4'], ['ธนาคาร กรุงเทพ', 'สาขาบางจาก', 'ออมทรัพย์', '179-0-85992-8']],
        'en' => [['Kasikorn Bank', 'On Nut 39 Branch', 'Savings', '746-2-14207-7'], ['Siam Commercial Bank', 'Bang Chak Branch', 'Savings', '089-2-17954-4'], ['Bangkok Bank', 'Bang Chak Branch', 'Savings', '179-0-85992-8']],
    ];

    public static function symbol(?string $cur): string { return self::SYMBOL[strtoupper((string) $cur)] ?? (string) $cur; }

    /** Customers to pick from: the ones with unbilled invoices first. */
    public static function customers(string $q): array
    {
        $par = [];
        $where = '';
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
            $where = "WHERE lower(c.name) LIKE ? OR lower(c.code) LIKE ? OR lower(coalesce(c.vat_number, '')) LIKE ?";
            $par = [$like, $like, $like];
        }

        return array_map(fn ($r) => ['id' => (string) $r['id']] + $r, DB::select("SELECT c.id, c.name, c.code, coalesce(x.n, 0)::int AS invoices FROM erp_customers c
                           LEFT JOIN (SELECT d.customer_id, count(*) AS n FROM erp_documents d WHERE NOT EXISTS (SELECT 1 FROM billing_note_invoices b WHERE b.invoice_number = d.invoice_number) GROUP BY d.customer_id) x ON x.customer_id = c.id
                           {$where} ORDER BY coalesce(x.n, 0) DESC, c.name LIMIT 40", $par, self::C));
    }

    /** @param int|string $id ERP ids can be larger than a browser can count exactly, so they travel as strings */
    public static function customer(int|string $id): ?array
    {
        if (! preg_match('/^\d{1,19}$/', (string) $id)) { return null; }
        $c = DB::first('SELECT c.*, a.addressee, a.field1, a.field2, a.field3, a.field4, a.field5, a.locality, a.region, a.postal_code FROM erp_customers c LEFT JOIN erp_addresses a ON a.id = c.mailing_address_id WHERE c.id = ?::bigint', [(string) $id], self::C);
        if (! $c) { return null; }
        $parts = [];
        foreach (['addressee', 'field1', 'field2', 'field3', 'field4', 'field5', 'locality', 'region', 'postal_code'] as $k) {
            $v = trim((string) ($c[$k] ?? ''));
            if ($v !== '') { $parts[] = $v; }
        }
        $c['address'] = implode(', ', $parts);

        return $c;
    }

    /** Invoices of a customer that are not on any billing note yet. */
    public static function invoices(int|string $customerId): array
    {
        $rows = DB::select("SELECT d.invoice_number, d.order_number, d.invoice_date, d.currency_code, d.amount AS net, d.is_credit AS doc_credit,
                                   r.invoice_amount, r.vat_amount, r.due_date, r.is_credit AS r_credit, pt.grace_period_in_days AS grace
                              FROM erp_documents d
                              LEFT JOIN erp_receivables r ON r.invoice_number = d.invoice_number
                              LEFT JOIN erp_orders o ON o.id = d.order_id LEFT JOIN erp_payment_terms pt ON pt.id = o.payment_term_id
                             WHERE d.customer_id = ?::bigint AND NOT EXISTS (SELECT 1 FROM billing_note_invoices b WHERE b.invoice_number = d.invoice_number)
                             ORDER BY d.invoice_number DESC LIMIT 300", [(string) $customerId], self::C);
        $out = [];
        foreach ($rows as $r) {
            $credit = $r['r_credit'] !== null ? filter_var($r['r_credit'], FILTER_VALIDATE_BOOL) : filter_var($r['doc_credit'], FILTER_VALIDATE_BOOL);
            $vat    = $r['vat_amount'] !== null ? (float) $r['vat_amount'] : round((float) $r['net'] * 0.07, 2);
            $amount = $r['invoice_amount'] !== null ? (float) $r['invoice_amount'] : round((float) $r['net'] + $vat, 2);
            $date   = $r['invoice_date'] ? substr((string) $r['invoice_date'], 0, 10) : null;
            $due    = $r['due_date'] ? substr((string) $r['due_date'], 0, 10) : ($date ? date('Y-m-d', strtotime($date.' +'.(int) $r['grace'].' days')) : null);
            $out[] = ['invoice_number' => (int) $r['invoice_number'], 'order_no' => (string) $r['order_number'], 'invoice_date' => $date, 'due_date' => $due, 'amount' => abs($amount), 'vat_amount' => abs($vat),
                'is_credit' => $credit, 'currency' => (string) ($r['currency_code'] ?: 'THB'), 'has_receivable' => $r['invoice_amount'] !== null];
        }

        return $out;
    }

    /** BI + yy + mm + 001, 002 … per month. */
    public static function nextNumber(string $date): string
    {
        $prefix = 'BI'.date('ym', strtotime($date));
        $max = (int) DB::scalar("SELECT coalesce(max(substring(billing_number FROM ?)::int), 0) FROM billing_notes WHERE billing_number ~ ?", [strlen($prefix) + 1, '^'.$prefix.'[0-9]+$'], self::C);

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param array{customer_id: int, invoices: int[], address: string, billing_date: string, remind_date: ?string, is_thai: bool, override: ?string} $in
     * @return int the new billing note id
     */
    public static function create(array $in, int $by, bool $isAdmin): int
    {
        $c = self::customer($in['customer_id']) ?? throw new \RuntimeException(__('Choose a customer.'));
        $want = array_values(array_unique(array_map('intval', $in['invoices'])));
        if (! $want) { throw new \RuntimeException(__('Choose at least one invoice.')); }
        $avail = array_column(self::invoices((int) $c['id']), null, 'invoice_number');
        $rows = [];
        foreach ($want as $n) { isset($avail[$n]) ? $rows[] = $avail[$n] : throw new \RuntimeException(__('Invoice :no is not available (already billed, or not this customer\'s).', ['no' => $n])); }
        $address = trim((string) $in['address']) !== '' ? trim((string) $in['address']) : (string) $c['address'];
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in['billing_date']) ? (string) $in['billing_date'] : date('Y-m-d');
        $remind = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['remind_date'] ?? '')) ? (string) $in['remind_date'] : $date;
        $cur = $rows[0]['currency'];
        $total = 0.0;
        foreach ($rows as $r) { $total += $r['is_credit'] ? -$r['amount'] : $r['amount']; }

        $pdo = DB::connection(self::C);
        $pdo->beginTransaction();
        try {
            $pdo->exec('SELECT pg_advisory_xact_lock(7001)');                                  // two people generating at once get different numbers
            $number = $isAdmin && trim((string) ($in['override'] ?? '')) !== '' ? trim((string) $in['override']) : self::nextNumber($date);
            if (DB::scalar('SELECT 1 FROM billing_notes WHERE billing_number = ?', [$number], self::C)) { throw new \RuntimeException(__('The number :n is already used.', ['n' => $number])); }
            $id = (int) DB::insert('billing_notes', ['billing_number' => $number, 'is_thai' => $in['is_thai'] ? 'true' : 'false', 'customer_id' => $c['id'], 'customer_code' => $c['code'], 'customer_name' => $c['name'],
                'vat_number' => $c['vat_number'] ?: ($c['corporation_identification_number'] ?? null), 'address' => $address, 'total_amount' => round($total, 2), 'currency_code' => $cur, 'billing_at' => $date, 'remind_date' => $remind,
                'created_by' => $by, 'updated_by' => $by], self::C);
            foreach ($rows as $r) {
                DB::insert('billing_note_invoices', ['billing_id' => $id, 'invoice_number' => $r['invoice_number'], 'order_no' => $r['order_no'], 'amount' => $r['amount'], 'vat_amount' => $r['vat_amount'],
                    'is_credit' => $r['is_credit'] ? 'true' : 'false', 'invoice_date' => $r['invoice_date'], 'due_date' => $r['due_date']], self::C);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Log::info('billing', $number.': created', ['customer' => $c['code'], 'invoices' => count($rows), 'total' => round($total, 2), 'by' => $by]);
        try { self::pdf($id, true); } catch (\Throwable $e) { Log::exception('billing', $e, $number.' PDF not made'); }

        return $id;
    }

    /** The PDF of a note: made once and kept (storage/billing/<number>.pdf); $force builds it again. @return string absolute path */
    public static function pdf(int $id, bool $force = false): string
    {
        $n = DB::first('SELECT * FROM billing_notes WHERE id = ?', [$id], self::C) ?? throw new \RuntimeException(__('Billing note not found.'));
        $key = 'billing/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $n['billing_number']).'.pdf';
        $abs = BASE_PATH.'/storage/'.$key;
        if (! $force && $n['file_key'] && is_file(BASE_PATH.'/storage/'.$n['file_key'])) { return BASE_PATH.'/storage/'.$n['file_key']; }
        $rows = DB::select('SELECT * FROM billing_note_invoices WHERE billing_id = ? ORDER BY invoice_number', [$id], self::C);
        $company = Company::require();
        $html = PdfRenderer::billingHtml($n, $rows, $company, self::banks(filter_var($n['is_thai'], FILTER_VALIDATE_BOOL)));
        $r = PdfRenderer::renderMany(['b' => $html], 1)['b'];
        if (! is_string($r)) { throw $r; }
        is_dir(dirname($abs)) || @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $r);
        DB::exec('UPDATE billing_notes SET file_key = ?, updated_at = now() WHERE id = ?', [$key, $id], self::C);

        return $abs;
    }

    /** A note with its invoices, for the detail and edit pages. */
    public static function get(int $id): ?array
    {
        $n = DB::first('SELECT * FROM billing_notes WHERE id = ?', [$id], self::C);
        if (! $n) {
            return null;
        }
        $n['rows'] = DB::select('SELECT * FROM billing_note_invoices WHERE billing_id = ? ORDER BY invoice_number', [$id], self::C);

        return $n;
    }

    /** What can be on this note: its own invoices plus the customer's invoices that are not billed yet. @return array<int, array> by invoice number */
    public static function choices(array $note): array
    {
        $out = [];
        foreach ($note['rows'] as $r) {
            $out[(int) $r['invoice_number']] = ['invoice_number' => (int) $r['invoice_number'], 'order_no' => (string) $r['order_no'], 'invoice_date' => $r['invoice_date'], 'due_date' => $r['due_date'], 'amount' => (float) $r['amount'],
                'vat_amount' => (float) $r['vat_amount'], 'is_credit' => filter_var($r['is_credit'], FILTER_VALIDATE_BOOL), 'currency' => $note['currency_code'], 'on_note' => true];
        }
        if ($note['customer_id']) {
            foreach (self::invoices($note['customer_id']) as $r) {
                $out[$r['invoice_number']] ??= $r + ['on_note' => false];
            }
        }
        krsort($out);

        return $out;
    }

    /** Change a note: address, dates, language, remark, the invoices on it (and its number, administrators only). Builds the PDF again. */
    public static function update(int $id, array $in, int $by, bool $isAdmin): void
    {
        $n = self::get($id) ?? throw new \RuntimeException(__('Billing note not found.'));
        if (filter_var($n['is_complete'], FILTER_VALIDATE_BOOL) && ! $isAdmin) {
            throw new \RuntimeException(__('A complete billing note can only be changed by an administrator.'));
        }
        $choices = self::choices($n);
        $want = array_values(array_unique(array_map('intval', (array) ($in['invoices'] ?? []))));
        if (! $want) { throw new \RuntimeException(__('Choose at least one invoice.')); }
        $rows = [];
        foreach ($want as $no) { isset($choices[$no]) ? $rows[] = $choices[$no] : throw new \RuntimeException(__('Invoice :no is not available (already billed, or not this customer\'s).', ['no' => $no])); }
        $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['billing_date'] ?? '')) ? (string) $in['billing_date'] : (string) $n['billing_at'];
        $remind = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['remind_date'] ?? '')) ? (string) $in['remind_date'] : $date;
        $address = trim((string) ($in['address'] ?? '')); $address = $address !== '' ? $address : (string) $n['address'];
        $total = 0.0;
        foreach ($rows as $r) { $total += $r['is_credit'] ? -$r['amount'] : $r['amount']; }
        $number = (string) $n['billing_number'];
        if ($isAdmin && trim((string) ($in['billing_number'] ?? '')) !== '' && trim((string) $in['billing_number']) !== $number) {
            $number = trim((string) $in['billing_number']);
            if (DB::scalar('SELECT 1 FROM billing_notes WHERE billing_number = ? AND id <> ?', [$number, $id], self::C)) { throw new \RuntimeException(__('The number :n is already used.', ['n' => $number])); }
        }
        $pdo = DB::connection(self::C);
        $pdo->beginTransaction();
        try {
            DB::exec('DELETE FROM billing_note_invoices WHERE billing_id = ?', [$id], self::C);
            foreach ($rows as $r) {
                DB::insert('billing_note_invoices', ['billing_id' => $id, 'invoice_number' => $r['invoice_number'], 'order_no' => $r['order_no'], 'amount' => $r['amount'], 'vat_amount' => $r['vat_amount'],
                    'is_credit' => $r['is_credit'] ? 'true' : 'false', 'invoice_date' => $r['invoice_date'], 'due_date' => $r['due_date']], self::C);
            }
            DB::update('billing_notes', ['billing_number' => $number, 'is_thai' => ! empty($in['is_thai']) ? 'true' : 'false', 'address' => $address, 'billing_at' => $date, 'remind_date' => $remind, 'total_amount' => round($total, 2),
                'currency_code' => $rows[0]['currency'] ?: $n['currency_code'], 'note' => trim((string) ($in['note'] ?? '')) ?: null, 'remark' => trim((string) ($in['remark'] ?? '')) ?: null, 'updated_by' => $by, 'updated_at' => now()], ['id' => $id], self::C);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($number !== $n['billing_number'] && $n['file_key']) { @unlink(BASE_PATH.'/storage/'.$n['file_key']); DB::exec('UPDATE billing_notes SET file_key = NULL WHERE id = ?', [$id], self::C); }
        Log::info('billing', $number.': edited', ['invoices' => count($rows), 'total' => round($total, 2), 'by' => $by]);
        try { self::pdf($id, true); } catch (\Throwable $e) { Log::exception('billing', $e, $number.' PDF not made'); }
    }

    public static function banks(bool $thai): array { return self::BANKS[$thai ? 'th' : 'en']; }

    public static function remove(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $row = DB::first('SELECT billing_number, file_key FROM billing_notes WHERE id = ?', [(int) $id], self::C);
            if (! $row) { continue; }
            if ($row['file_key']) { @unlink(BASE_PATH.'/storage/'.$row['file_key']); }
            DB::exec('DELETE FROM billing_notes WHERE id = ?', [(int) $id], self::C);
            Log::warn('billing', $row['billing_number'].': removed');
            $n++;
        }

        return $n;
    }
}
