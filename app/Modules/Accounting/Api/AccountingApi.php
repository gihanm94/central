<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Api;

use App\Core\Api\ApiException;
use App\Core\Api\Controller;
use App\Core\Api\File;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Request;
use App\Modules\Accounting\Billing\BillingService;
use App\Modules\Accounting\Support\Dashboard;

/**
 * Accounting for the mobile app — READ ONLY. Look up customers, products, orders, invoices and sellers;
 * view e-tax documents (Inet) and billing notes and download their stored PDFs; see the overview figures.
 * Nothing here generates, sends, syncs or rebuilds anything: a PDF that was never generated is a 404,
 * and the ERP schedule is not started by API requests. Permissions are the website's (accounting_* view).
 */
class AccountingApi extends Controller
{
    private const C = 'accounting';
    private const DOCS = ['388' => 'Tax invoice', 't01' => 'Receipt', '81' => 'Credit note'];

    /* ----------------------------------------------------------------- overview */

    /** The accounting overview, only the blocks the person may see (same as the website). */
    public function stats(): array
    {
        $u = $this->user();
        $show = ['finance' => $u->can('accounting_invoices', 'view'), 'orders' => $u->can('accounting_orders', 'view'),
            'inet' => $u->can('accounting_inet', 'view'), 'billing' => $u->can('accounting_billing', 'view')];
        if (! array_filter($show)) {
            throw new ApiException(403, 'forbidden', __("You don't have permission to view the accounting overview."));
        }
        $d = Dashboard::get();
        $blocks = [
            'finance' => ['year', 'by_year', 'month_cur', 'month_prev', 'kpi', 'customers', 'sellers', 'products', 'aging', 'receivable'],
            'orders' => ['orders_month', 'orders_this_month'],
            'inet' => ['inet', 'inet_month', 'not_in_inet'],
            'billing' => ['billing_status', 'billing_month', 'billing_today', 'billing_open'],
        ];
        $out = ['built' => $d['built'] ?? null];
        foreach ($blocks as $block => $keys) {
            if ($show[$block]) {
                $out[$block] = array_intersect_key($d, array_flip($keys));
            }
        }

        return ['data' => $out];
    }

    /* ------------------------------------------------------------------ lookups */

    private const CUSTOMER_SQL = "SELECT t.id, t.code, t.name, t.alternative_name, t.vat_number, t.corporation_identification_number, t.is_private_customer, t.credit_limit,
            concat_ws(' ', a.addressee, a.field1, a.field2, a.field3, a.locality, a.region, a.postal_code) AS address,
            nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name
        FROM erp_customers t LEFT JOIN erp_addresses a ON a.id = t.mailing_address_id LEFT JOIN erp_sellers s ON s.id = t.seller_id";

    public function customers(): array
    {
        $this->need('accounting_customers');
        [$w, $p] = ['TRUE', []];
        if (($q = $this->q()) !== '') {
            $w = '(t.name ILIKE ? OR t.code ILIKE ? OR t.alternative_name ILIKE ? OR t.vat_number ILIKE ? OR t.corporation_identification_number ILIKE ?)';
            $p = array_fill(0, 5, $this->like($q));
        }

        return $this->paged(self::CUSTOMER_SQL." WHERE {$w}", $p, 't.name, t.id', self::C);
    }

    /** A customer and everything linked to it that the person may see: recent invoices and orders, Inets, billing notes, matching CRM leads. */
    public function customer(int $id): array
    {
        $this->need('accounting_customers');
        $c = DB::first(self::CUSTOMER_SQL.' WHERE t.id = ?', [$id], self::C) ?? $this->notFound();
        $u = $this->user();
        $links = [];
        if ($u->can('accounting_invoices', 'view')) {
            $links['invoices'] = DB::select('SELECT invoice_number, max(invoice_date) AS invoice_date, max(currency_code) AS currency_code,
                sum(coalesce(invoiced_quantity, 0) * coalesce(price, 0) * (1 - coalesce(discount, 0) / 100)) AS amount
                FROM erp_invoices WHERE customer_id = ? AND invoice_number IS NOT NULL GROUP BY invoice_number ORDER BY max(invoice_date) DESC, invoice_number DESC LIMIT 20', [$id], self::C);
        }
        if ($u->can('accounting_orders', 'view')) {
            $links['orders'] = DB::select('SELECT id, order_number, business_contact_order_number, order_date, status, is_credit FROM erp_orders WHERE customer_id = ? ORDER BY order_date DESC NULLS LAST, id DESC LIMIT 20', [$id], self::C);
        }
        if ($u->can('accounting_inet', 'view')) {
            $links['inets'] = DB::select('SELECT id, no_invoice, invoice_date, is_credit, total_amount, currency_code FROM inets WHERE customer_id = ? ORDER BY invoice_date DESC NULLS LAST, id DESC LIMIT 20', [$id], self::C);
        }
        if ($u->can('accounting_billing', 'view')) {
            $links['billing_notes'] = DB::select('SELECT id, billing_number, billing_at, total_amount, currency_code, is_paid, is_complete FROM billing_notes WHERE customer_id = ? ORDER BY id DESC LIMIT 20', [(string) $id], self::C);
        }
        // CRM leads with the same tax id, through the CRM's own visibility (only when the CRM is on and the person may see leads)
        if ($c['vat_number'] && Modules::usable('crm') && $u->can('crm_leads', 'view')) {
            $s = \App\Modules\CRM\Support\Access::visible($u, 't', 'lead');
            $links['crm_leads'] = DB::select("SELECT t.id, t.code, t.name_en FROM leads t WHERE t.deleted_at IS NULL AND regexp_replace(t.tax_id, '\\D', '', 'g') = regexp_replace(?, '\\D', '', 'g') AND {$s['sql']} LIMIT 5",
                [(string) $c['vat_number'], ...$s['params']], 'crm');
        }

        return ['data' => $c, 'links' => (object) $links];
    }

    public function products(): array
    {
        $this->need('accounting_products');
        [$w, $p] = ['TRUE', []];
        if (($q = $this->q()) !== '') {
            $w = '(t.part_number ILIKE ? OR t.description ILIKE ? OR t.alias ILIKE ?)';
            $p = array_fill(0, 3, $this->like($q));
        }

        return $this->paged("SELECT t.id, t.part_number, t.description, t.alias, t.standard_price FROM erp_products t WHERE {$w}", $p, 't.part_number, t.id', self::C);
    }

    public function product(int $id): array
    {
        $this->need('accounting_products');

        return ['data' => DB::first('SELECT id, part_number, description, alias, standard_price, part_template_id, part_code_id FROM erp_products WHERE id = ?', [$id], self::C) ?? $this->notFound()];
    }

    private const ORDER_SQL = "SELECT t.id, t.order_number, t.business_contact_order_number, t.order_date, t.vat_number, t.is_credit, t.status, t.customer_id,
            c.code AS customer_code, c.name AS customer_name, nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name,
            (SELECT sum(coalesce(r.ordered_quantity, 0) * coalesce(r.price, 0) * (1 - coalesce(r.discount, 0) / 100)) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS total
        FROM erp_orders t LEFT JOIN erp_customers c ON c.id = t.customer_id LEFT JOIN erp_sellers s ON s.id = t.seller_id";

    /** ?q= ?customer_id= ?credit=0|1 */
    public function orders(): array
    {
        $this->need('accounting_orders');
        [$w, $p] = ['TRUE', []];
        if (($q = $this->q()) !== '') {
            $w .= ' AND (t.order_number::text ILIKE ? OR t.business_contact_order_number ILIKE ? OR t.vat_number ILIKE ? OR c.name ILIKE ? OR c.code ILIKE ?)';
            array_push($p, ...array_fill(0, 5, $this->like($q)));
        }
        if ($cid = (int) Request::query('customer_id')) { $w .= ' AND t.customer_id = ?'; $p[] = $cid; }
        if (in_array(Request::query('credit'), ['0', '1'], true)) { $w .= ' AND coalesce(t.is_credit, false) = ?'; $p[] = Request::query('credit') === '1'; }

        return $this->paged(self::ORDER_SQL." WHERE {$w}", $p, 't.order_date DESC NULLS LAST, t.id DESC', self::C);
    }

    public function order(int $id): array
    {
        $this->need('accounting_orders');
        $o = DB::first(self::ORDER_SQL.' WHERE t.id = ?', [$id], self::C) ?? $this->notFound();
        $o['rows'] = DB::select('SELECT r.row_index, p.part_number, coalesce(r.additional_row_description, p.description) AS description, r.ordered_quantity, r.price, r.discount, r.delivery_date
            FROM erp_order_rows r LEFT JOIN erp_products p ON p.id = r.part_id WHERE r.parent_order_id = ? ORDER BY r.row_index, r.id', [$id], self::C);

        return ['data' => $o];
    }

    private const INVOICE_SQL = "SELECT i.invoice_number, max(i.invoice_date) AS invoice_date, max(i.customer_id) AS customer_id, max(i.customer_code) AS customer_code,
            max(c.name) AS customer_name, max(i.customer_order_number) AS customer_order_number, max(i.currency_code) AS currency_code, max(i.seller) AS seller, count(*) AS line_count,
            sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100)) AS amount
        FROM erp_invoices i LEFT JOIN erp_customers c ON c.id = i.customer_id WHERE i.invoice_number IS NOT NULL %s GROUP BY i.invoice_number";

    /** ?q= ?customer_id= */
    public function invoices(): array
    {
        $this->need('accounting_invoices');
        [$w, $p] = ['', []];
        if (($q = $this->q()) !== '') {
            $w .= ' AND (i.invoice_number::text ILIKE ? OR i.customer_code ILIKE ? OR c.name ILIKE ? OR i.customer_order_number ILIKE ?)';
            array_push($p, ...array_fill(0, 4, $this->like($q)));
        }
        if ($cid = (int) Request::query('customer_id')) { $w .= ' AND i.customer_id = ?'; $p[] = $cid; }

        return $this->paged(sprintf(self::INVOICE_SQL, $w), $p, 'invoice_date DESC NULLS LAST, invoice_number DESC', self::C);
    }

    public function invoice(string|int $number): array
    {
        $number = (string) $number;
        $this->need('accounting_invoices');
        if (! preg_match('/^[A-Za-z0-9._-]{1,40}$/', $number)) {
            $this->notFound();
        }
        $inv = DB::first(sprintf(self::INVOICE_SQL, ' AND i.invoice_number::text = ?'), [$number], self::C) ?? $this->notFound();
        $inv['lines'] = DB::select('SELECT part_number, invoiced_quantity, price, discount, unit_code FROM erp_invoices WHERE invoice_number::text = ? ORDER BY id', [$number], self::C);

        return ['data' => $inv];
    }

    public function sellers(): array
    {
        $this->need('accounting_sellers');
        [$w, $p] = ['TRUE', []];
        if (($q = $this->q()) !== '') {
            $w = "(concat_ws(' ', t.first_name, t.last_name) ILIKE ? OR t.employee_number ILIKE ? OR t.email ILIKE ? OR t.email_address ILIKE ?)";
            $p = array_fill(0, 4, $this->like($q));
        }

        return $this->paged("SELECT t.id, nullif(trim(concat_ws(' ', t.first_name, t.last_name)), '') AS name, t.employee_number, t.phone_number, coalesce(t.email, t.email_address) AS email, d.name AS department
            FROM erp_sellers t LEFT JOIN erp_departments d ON d.id = t.department_id WHERE {$w}", $p, 't.first_name, t.last_name, t.id', self::C);
    }

    /* -------------------------------------------------------------------- Inets */

    private const INET_LIST = ['id', 'no_invoice', 'custom_invoice_no', 'invoice_date', 'is_credit', 'is_international', 'customer_id', 'customer_code', 'customer_name',
        'customer_order_number', 'seller_name', 'currency_code', 'amount', 'tax_amount', 'total_amount'];

    /** ?q= ?credit=1 (credit notes) ?send=sent|unsent ?customer_id= */
    public function inets(): array
    {
        $this->need('accounting_inet');
        $credit = Request::query('credit') === '1';
        $w = 't.is_credit = ?';
        $p = [$credit];
        if (($q = $this->q()) !== '') {
            $w .= ' AND (t.no_invoice::text ILIKE ? OR t.customer_code ILIKE ? OR t.customer_name ILIKE ? OR t.customer_order_number ILIKE ? OR t.tax_id ILIKE ?)';
            array_push($p, ...array_fill(0, 5, $this->like($q)));
        }
        $sent = $credit ? 't.is_81_send' : '(t.is_388_send AND t.is_t01_send)';
        if (Request::query('send') === 'sent') { $w .= " AND coalesce({$sent}, false)"; }
        if (Request::query('send') === 'unsent') { $w .= " AND NOT coalesce({$sent}, false)"; }
        if ($cid = (int) Request::query('customer_id')) { $w .= ' AND t.customer_id = ?'; $p[] = $cid; }

        return $this->paged("SELECT t.* FROM inets t WHERE {$w}", $p, 't.no_invoice DESC', self::C, fn ($r) => $this->inetOut($r));
    }

    public function inet(int $id): array
    {
        $this->need('accounting_inet');

        return ['data' => $this->inetOut(DB::first('SELECT * FROM inets WHERE id = ?', [$id], self::C) ?? $this->notFound(), true)];
    }

    private function inetOut(array $r, bool $full = false): array
    {
        $out = self::pick($r, $full ? [...self::INET_LIST, 'tax_id', 'branch', 'email', 'delivery_note_number', 'department_name', 'exchange_rate', 'tax_rate', 'product_count', 'remark'] : self::INET_LIST);
        $docs = [];
        foreach (filter_var($r['is_credit'], FILTER_VALIDATE_BOOL) ? ['81'] : ['388', 't01'] as $c) {
            $docs[] = ['type' => $c, 'label' => __(self::DOCS[$c]), 'generated' => (bool) $r["pdf_{$c}"], 'sent' => filter_var($r["is_{$c}_send"], FILTER_VALIDATE_BOOL),
                'signed' => (bool) $r["inet_pdf_{$c}"], 'message' => $full ? $r["message_{$c}"] : null,
                'pdf_url' => $r["pdf_{$c}"] ? url('/api/v1/accounting/inets/'.$r['id'].'/pdf/'.$c) : null,
                'signed_pdf_url' => $r["inet_pdf_{$c}"] ? url('/api/v1/accounting/inets/'.$r['id'].'/pdf/'.$c, ['signed' => 1]) : null];
        }
        $out['documents'] = $docs;

        return $out;
    }

    /** The stored PDF of one document of an Inet (?signed=1: the copy signed by INET). Never generates one. */
    public function inetPdf(int $id, string|int $doc): File      // the router hands digit-only parts over as int
    {
        $doc = (string) $doc;
        $this->need('accounting_inet');
        if (! isset(self::DOCS[$doc])) {
            $this->notFound();
        }
        $r = DB::first('SELECT * FROM inets WHERE id = ?', [$id], self::C) ?? $this->notFound();
        if (($doc === '81') !== filter_var($r['is_credit'], FILTER_VALIDATE_BOOL)) {
            $this->notFound();
        }
        $signed = Request::query('signed') === '1';
        $path = self::stored($r[$signed ? "inet_pdf_{$doc}" : "pdf_{$doc}"] ?? null, 'inet/')
            ?? throw new ApiException(404, 'pdf_not_available', $signed ? __('INET has not returned a signed copy yet.') : __('This document has not been generated yet.'));

        return new File($path, $r['no_invoice'].'_'.strtoupper($doc).($signed ? '_signed' : '').'.pdf');
    }

    /* ------------------------------------------------------------ billing notes */

    /** ?q= ?status=pending|overdue|paid|complete ?customer_id= */
    public function billingNotes(): array
    {
        $this->need('accounting_billing');
        $status = "CASE WHEN t.is_complete THEN 'complete' WHEN t.is_paid THEN 'paid' WHEN t.remind_date < CURRENT_DATE THEN 'overdue' ELSE 'pending' END";
        $w = 'TRUE';
        $p = [];
        if (($q = $this->q()) !== '') {
            $w .= ' AND (t.billing_number ILIKE ? OR t.customer_code ILIKE ? OR t.customer_name ILIKE ? OR t.vat_number ILIKE ?)';
            array_push($p, ...array_fill(0, 4, $this->like($q)));
        }
        if (in_array(Request::query('status'), ['pending', 'overdue', 'paid', 'complete'], true)) { $w .= " AND {$status} = ?"; $p[] = Request::query('status'); }
        if ($cid = (int) Request::query('customer_id')) { $w .= ' AND t.customer_id = ?'; $p[] = (string) $cid; }

        return $this->paged("SELECT t.id, t.billing_number, t.billing_at, t.remind_date, t.customer_id, t.customer_code, t.customer_name, t.vat_number, t.total_amount, t.currency_code,
            t.is_paid, t.is_complete, {$status} AS status, (SELECT count(*) FROM billing_note_invoices i WHERE i.billing_id = t.id) AS invoice_count, t.file_key IS NOT NULL AS has_pdf
            FROM billing_notes t WHERE {$w}", $p, 't.id DESC', self::C, function ($r) {
                $r['pdf_url'] = $r['has_pdf'] ? url('/api/v1/accounting/billing-notes/'.$r['id'].'/pdf') : null;

                return $r;
            });
    }

    public function billingNote(int $id): array
    {
        $this->need('accounting_billing');
        $n = BillingService::get($id) ?? $this->notFound();
        $out = self::pick($n, ['id', 'billing_number', 'is_thai', 'billing_at', 'remind_date', 'customer_id', 'customer_code', 'customer_name', 'vat_number', 'address',
            'total_amount', 'currency_code', 'note', 'remark', 'is_paid', 'is_complete', 'created_at']);
        $out['invoices'] = array_map(fn ($r) => self::pick($r, ['invoice_number', 'order_no', 'invoice_date', 'due_date', 'amount', 'vat_amount', 'is_credit']), $n['rows'] ?? []);
        $out['pdf_url'] = $n['file_key'] ? url('/api/v1/accounting/billing-notes/'.$id.'/pdf') : null;

        return ['data' => $out];
    }

    /** The stored PDF of a billing note. Unlike the website, never rebuilds it. */
    public function billingPdf(int $id): File
    {
        $this->need('accounting_billing');
        $n = DB::first('SELECT billing_number, file_key FROM billing_notes WHERE id = ?', [$id], self::C) ?? $this->notFound();
        $path = self::stored($n['file_key'], 'billing/') ?? throw new ApiException(404, 'pdf_not_available', __('The PDF of this billing note has not been made yet. Open it once on the website.'));

        return new File($path, $n['billing_number'].'.pdf');
    }

    /** Absolute path of a stored file under storage/<prefix>, or null (never outside it). */
    private static function stored(?string $key, string $prefix): ?string
    {
        if (! $key || ! str_starts_with($key, $prefix) || str_contains($key, '..') || str_contains($key, "\0")) {
            return null;
        }
        $base = realpath(BASE_PATH.'/storage/'.$prefix);
        $path = realpath(BASE_PATH.'/storage/'.$key);

        return $base && $path && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
    }
}
