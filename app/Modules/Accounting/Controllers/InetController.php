<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Erp\Syncer;
use App\Modules\Accounting\Support\InetBuilder;
use App\Modules\CRM\Support\Ui;

/**
 * e-Tax (INET) page: one row per invoice / credit note. "Generate" picks invoices that came from the ERP and are not
 * here yet and prepares their e-tax rows (customer, order, amounts). Sync re-reads the invoice from the ERP.
 * Producing the 388 / T01 / 81 documents and sending them to INET is the next step (buttons are shown but off).
 */
class InetController extends ErpListController
{
    protected string $resource = 'accounting_inet';
    protected string $table = 'inets';
    protected string $type = 'inet';
    protected string $singular = 'e-tax invoice';
    protected string $plural = 'inets';
    protected string $base = '/accounting/inet';
    protected string $icon = 'doc';
    protected string $orderBy = 't.no_invoice DESC';
    private const PER = 10;

    private function credit(): bool { return Request::query('credit') === '1'; }

    protected function scopeSql(): array { return ['sql' => 't.is_credit = ?', 'params' => [$this->credit() ? 'true' : 'false']]; }

    protected function label(array $row): string { return (string) $row['no_invoice']; }
    protected function searchable(): array { return ['t.no_invoice', 't.customer_code', 't.customer_name', 't.customer_order_number', 't.tax_id']; }

    protected function filters(): array
    {
        $sent = $this->credit() ? 't.is_81_send' : '(t.is_388_send AND t.is_t01_send)';

        return ['send' => ['label' => __('Send status'), 'options' => ['sent' => __('Sent'), 'unsent' => __('Not sent')], 'sql' => "CASE ? WHEN 'sent' THEN {$sent} ELSE NOT {$sent} END"]];
    }

    protected function detail(): array
    {
        return ['no_invoice' => ['Invoice number', 'text'], 'invoice_date' => ['Invoice date', 'date'], 'customer_code' => ['Customer code', 'text'], 'customer_name' => ['Customer', 'text'],
            'customer_order_number' => ['Order number', 'text'], 'delivery_note_number' => ['Delivery number', 'text'], 'seller_name' => ['Seller', 'text'], 'department_name' => ['Department', 'text'],
            'tax_id' => ['Tax ID', 'text'], 'email' => ['E-mail', 'text'], 'currency_code' => ['Currency', 'text'], 'amount' => ['Amount', 'number'], 'tax_rate' => ['VAT rate (%)', 'number'],
            'tax_amount' => ['VAT', 'number'], 'total_amount' => ['Total', 'number'], 'product_count' => ['Lines', 'number'], 'is_international' => ['International', 'checkbox'], 'is_credit' => ['Credit note', 'checkbox']];
    }

    /** Sync / Send / Inet buttons of one document type. Send and Inet need the e-tax step that follows, so they stay off. */
    private function docButtons(array $r, string $code, string $gen): string
    {
        $on   = filter_var($r['is_'.$gen.'_generate'], FILTER_VALIDATE_BOOL);
        $btn  = 'inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs ring-1 ';
        $off  = $btn.'cursor-not-allowed text-graphite-400 ring-graphite-900/10';
        $sync = can($this->resource, 'edit')
            ? '<form method="POST" action="'.e(url('/accounting/inet/'.$r['id'].'/sync')).'" class="inline">'.csrf_field().'<button class="'.$btn.'text-graphite-800 ring-graphite-900/20 hover:bg-mist" title="'.e(__('Read this invoice from the ERP again')).'">'.icon('bolt', 'size-3.5').' '.e(__('Sync')).'</button></form>'
            : '<span class="'.$off.'">'.icon('bolt', 'size-3.5').' '.e(__('Sync')).'</span>';

        return '<span class="inline-flex items-center gap-1.5" data-doc="'.e($code).'">'.$sync
            .'<span class="'.$off.'" title="'.e(__('Sending to INET comes with the e-tax step')).'">'.icon('send', 'size-3.5').' '.e(__('Send')).'</span>'
            .'<span class="'.($on ? $btn.'bg-emerald-50 text-emerald-800 ring-emerald-200' : $off).'">'.icon('download', 'size-3.5').' Inet</span></span>';
    }

    protected function columns(): array
    {
        $cols = [
            'no'       => ['label' => __('Invoice number'), 'primary' => true, 'sort' => 't.no_invoice', 'render' => fn ($r) => '<a href="'.e(url('/accounting/inet/'.$r['id'])).'" class="font-medium hover:text-signal-700">'.e($r['no_invoice']).'</a><span class="block text-xs text-steel">'.e($r['customer_order_number'] ?? '').'</span>'],
            'customer' => ['label' => __('Customer code'), 'sort' => 't.customer_code', 'render' => fn ($r) => '<span title="'.e($r['customer_name']).'">'.e($r['customer_code'] ?: '—').'</span>'],
            'intl'     => ['label' => __('International'), 'sort' => 't.is_international', 'render' => fn ($r) => filter_var($r['is_international'], FILTER_VALIDATE_BOOL) ? Ui::badge(__('Yes'), 'info') : Ui::badge(__('No'), 'neutral')],
            'amount'   => ['label' => __('Amount'), 'sort' => 't.total_amount', 'render' => fn ($r) => self::money($r['total_amount'], $r['currency_code'] !== 'THB' ? $r['currency_code'] : '฿')],
        ];
        if ($this->credit()) {
            $cols['credit'] = ['label' => __('Credit note'), 'render' => fn ($r) => $this->docButtons($r, '81', '81')];
        } else {
            $cols['invoice'] = ['label' => __('Invoice'), 'render' => fn ($r) => $this->docButtons($r, '388', '388')];
            $cols['receipt'] = ['label' => __('Receipt'), 'render' => fn ($r) => $this->docButtons($r, 'T01', 't01')];
        }
        $cols['seller'] = ['label' => __('Seller'), 'render' => fn ($r) => e($r['seller_name'] ?: '—')];

        return $cols;
    }

    /* --------------------------------------------------------- tabs + dialog */

    protected function topExtra(): string
    {
        $tab = fn (bool $credit, string $label) => '<a href="'.e(url('/accounting/inet', $credit ? ['credit' => 1] : [])).'" class="rounded-md px-3 py-1.5 text-sm '.($this->credit() === $credit ? 'bg-signal-600 font-medium text-white' : 'text-steel hover:bg-mist').'">'.e($label).'</a>';

        return '<nav class="mt-4 inline-flex gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-graphite-900/10" aria-label="'.e(__('Document type')).'">'.$tab(false, __('Invoice')).$tab(true, __('Credit note')).'</nav>'
            .partial('accounting/inet-dialog', ['credit' => $this->credit()]);
    }

    protected function headerActions(): string
    {
        return can($this->resource, 'create')
            ? '<button type="button" class="btn-primary" data-inet-open data-url="'.e(url('/accounting/inet/candidates')).'">'.icon('bolt', 'size-4').' '.e(__('Generate')).'</button>' : '';
    }

    /* ---------------------------------------------------------------- actions */

    /** Invoices that are in the ERP copy and not yet here, 10 a page: {rows, pages, total}. */
    public function candidates(): never
    {
        $this->authorize($this->resource, 'create');
        $credit = Request::query('credit') === '1';
        $q      = trim((string) Request::query('q', ''));
        $page   = max(1, (int) Request::query('page', 1));
        $where  = ['i.invoice_number IS NOT NULL', 'coalesce(o.is_credit, false) = ?', 'NOT EXISTS (SELECT 1 FROM inets n WHERE n.no_invoice = i.invoice_number)'];
        $par    = [$credit ? 'true' : 'false'];
        $having = '';
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $having = " HAVING (i.invoice_number::text ILIKE ? OR max(i.customer_order_number) ILIKE ? OR max(o.vat_number) ILIKE ? OR max(i.customer_code) ILIKE ?)";
            array_push($par, $like, $like, $like, $like);
        }
        $from = 'FROM erp_invoices i LEFT JOIN erp_orders o ON o.id = i.customer_order_id WHERE '.implode(' AND ', $where).' GROUP BY i.invoice_number'.$having;
        $total = (int) DB::scalar("SELECT count(*) FROM (SELECT i.invoice_number {$from}) x", $par, ErpSettings::CONN);
        $rows = DB::select("SELECT i.invoice_number, max(i.customer_order_number) AS order_no, max(o.vat_number) AS vat_no, max(i.customer_code) AS customer_code,
                                   (SELECT oi.delivery_note_number FROM erp_order_invoices oi WHERE oi.business_contact_order_id = max(i.customer_order_id) ORDER BY oi.id LIMIT 1) AS delivery_no
                            {$from} ORDER BY i.invoice_number DESC LIMIT ".self::PER.' OFFSET '.(($page - 1) * self::PER), $par, ErpSettings::CONN);

        json_response(['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / self::PER))]);
    }

    /** Create the e-tax rows of the chosen invoices. Body (JSON): {items: [{invoice: 123, auto_pdf: true}]} */
    public function generate(): never
    {
        $this->authorize($this->resource, 'create');
        $items = (array) Request::input('items', []);
        if (! $items || count($items) > 500) {
            json_response(['message' => __('Choose between 1 and 500 invoices.')], 422);
        }
        $made = $missing = 0;
        foreach ($items as $it) {
            $no = (int) ($it['invoice'] ?? 0);
            if ($no > 0 && InetBuilder::save($no, ! empty($it['auto_pdf']), $this->user()->id)) { $made++; } else { $missing++; }
        }
        if ($made) {
            Activity::log('created', 'inet', null, (string) $made, ['Prepared :n e-tax invoice(s)', ['n' => $made]], [], null, null, false, 'accounting');
        }
        Session::flash($made ? 'success' : 'error', $made ? __(':n invoice(s) generated.', ['n' => $made]).($missing ? ' '.__(':n not found in the ERP copy.', ['n' => $missing]) : '') : __('None of them is in the ERP copy.'));
        json_response(['ok' => true, 'made' => $made, 'missing' => $missing]);
    }

    /** Read the invoice (and its order rows) from the ERP again and refresh the figures. */
    public function sync(int $id): never
    {
        $this->authorize($this->resource, 'edit');
        $row = DB::first('SELECT * FROM inets WHERE id = ?', [$id], ErpSettings::CONN) ?? abort(404);
        try {
            $s = new Syncer();
            $s->run('invoice', 'all', null, null, 'InvoiceNumber eq '.(int) $row['no_invoice']);
            if ($row['order_id']) {
                $s->run('order', 'one', (string) $row['order_id']);
                $s->run('order_row', 'all', null, null, "ParentOrderId eq '".(int) $row['order_id']."'");
            }
            InetBuilder::save((int) $row['no_invoice'], ! filter_var($row['is_manual'], FILTER_VALIDATE_BOOL), $this->user()->id);
            Session::flash('success', __('Invoice :no read from the ERP again.', ['no' => $row['no_invoice']]));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect('/accounting/inet'.($row['is_credit'] === true || $row['is_credit'] === 't' ? '?credit=1' : ''));
    }
}
