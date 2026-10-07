<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Modules\Accounting\Billing\BillingService;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\CRM\Support\Ui;

/** Billing notes: list, the 4-step generate page, PDF view / download, paid / complete. */
class BillingController extends ErpListController
{
    protected string $resource = 'accounting_billing';
    protected string $table = 'billing_notes';
    protected string $type = 'billing_note';
    protected string $singular = 'billing note';
    protected string $plural = 'billing notes';
    protected string $base = '/accounting/billing';
    protected string $icon = 'doc';
    protected string $orderBy = 't.id DESC';

    protected function select(): string
    {
        return "SELECT t.*, CASE WHEN t.is_complete THEN 'complete' WHEN t.is_paid THEN 'paid' WHEN t.remind_date < CURRENT_DATE THEN 'overdue' ELSE 'pending' END AS status,
                       (SELECT count(*) FROM billing_note_invoices b WHERE b.billing_id = t.id) AS invoice_count FROM billing_notes t";
    }

    protected function canModify(array $row, string $action): bool
    {
        return $action === 'delete' || ($action === 'edit' && (! filter_var($row['is_complete'], FILTER_VALIDATE_BOOL) || $this->user()->isAdmin()));
    }
    protected function meta(): array { return parent::meta() + ['selectable' => can($this->resource, 'delete')]; }
    protected function label(array $row): string { return (string) $row['billing_number']; }
    protected function searchable(): array { return ['t.billing_number', 't.customer_code', 't.customer_name', 't.vat_number']; }

    protected function filters(): array
    {
        return ['status' => ['label' => __('Status'), 'options' => ['pending' => __('Pending'), 'overdue' => __('Overdue'), 'paid' => __('Paid'), 'complete' => __('Complete')],
            'sql' => "CASE WHEN t.is_complete THEN 'complete' WHEN t.is_paid THEN 'paid' WHEN t.remind_date < CURRENT_DATE THEN 'overdue' ELSE 'pending' END = ?"]];
    }

    protected function detail(): array
    {
        return ['billing_number' => ['Billing number', 'text'], 'customer_name' => ['Customer', 'text'], 'customer_code' => ['Customer code', 'text'], 'total_amount' => ['Total amount', 'number'],
            'billing_at' => ['Billing date', 'date'], 'remind_date' => ['Remind date', 'date'], 'status' => ['Status', 'text']];
    }

    protected function columns(): array
    {
        $tone = ['pending' => 'neutral', 'overdue' => 'danger', 'paid' => 'info', 'complete' => 'success'];

        return [
            'no'     => ['label' => __('Billing number'), 'primary' => true, 'sort' => 't.billing_number', 'render' => fn ($r) => '<a href="'.e(url('/accounting/billing/'.$r['id'].'/pdf')).'" data-bill-view data-title="'.e($r['billing_number']).'" class="font-medium hover:text-signal-700">'.e($r['billing_number']).'</a><span class="block text-xs text-steel">'.e((string) $r['invoice_count']).' '.e(__('invoices')).'</span>'],
            'cust'   => ['label' => __('Customer'), 'sort' => 't.customer_name', 'render' => fn ($r) => '<span class="block max-w-md truncate" title="'.e($r['customer_name']).'">'.e($r['customer_name']).'</span><span class="block text-xs text-steel">'.e($r['customer_code']).'</span>'],
            'total'  => ['label' => __('Total amount'), 'sort' => 't.total_amount', 'render' => fn ($r) => self::money($r['total_amount'], BillingService::symbol($r['currency_code']))],
            'status' => ['label' => __('Status'), 'sort' => 'status', 'render' => fn ($r) => Ui::badge(__(ucfirst($r['status'])), $tone[$r['status']] ?? 'neutral')],
            'bdate'  => ['label' => __('Billing date'), 'sort' => 't.billing_at', 'render' => fn ($r) => self::date($r['billing_at'])],
            'remind' => ['label' => __('Remind date'), 'sort' => 't.remind_date', 'render' => fn ($r) => self::date($r['remind_date'])],
        ];
    }

    protected function rowLinks(array $row): array
    {
        $b = $this->base.'/'.$row['id'];
        $l = [['url' => $b, 'label' => __('Open'), 'icon' => 'doc'], ['url' => $b.'/pdf', 'label' => __('View PDF'), 'icon' => 'eye'], ['url' => $b.'/pdf?download=1', 'label' => __('Download PDF'), 'icon' => 'download']];
        if (can($this->resource, 'edit')) {
            if ($this->canModify($row, 'edit')) { $l[] = ['url' => $b.'/edit', 'label' => __('Edit'), 'icon' => 'pencil']; }
            $l[] = ['url' => $b.'/regenerate', 'label' => __('Build the PDF again'), 'icon' => 'bolt', 'post' => true];
            if (! filter_var($row['is_paid'], FILTER_VALIDATE_BOOL)) { $l[] = ['url' => $b.'/state/paid', 'label' => __('Mark as paid'), 'icon' => 'check', 'post' => true]; }
            if (! filter_var($row['is_complete'], FILTER_VALIDATE_BOOL)) { $l[] = ['url' => $b.'/state/complete', 'label' => __('Mark as complete'), 'icon' => 'check', 'post' => true]; }
        }

        return $l;
    }

    protected function headerActions(): string
    {
        return can($this->resource, 'create') ? '<a href="'.e(url('/accounting/billing/create')).'" class="btn-primary">'.icon('bolt', 'size-4').' '.e(__('Generate')).'</a>' : '';
    }

    protected function topExtra(): string { return partial('accounting/billing-viewer'); }

    /* --------------------------------------------------------------- generate page */

    public function create(): string
    {
        $this->authorize($this->resource, 'create');

        return view('accounting/billing-create', ['title' => __('Generate billing note'), 'isAdmin' => $this->user()->isAdmin(), 'notifyTime' => ErpSettings::get('notify.time', '08:30')]);
    }

    public function customers(): never
    {
        $this->authorize($this->resource, 'create');
        session_write_close();
        try { $rows = BillingService::customers(trim((string) Request::query('q', ''))); }
        catch (\Throwable $e) { error_log('[billing] customers: '.$e->getMessage()); json_response(['error' => $e->getMessage(), 'rows' => []], 500); }
        json_response(['rows' => $rows]);
    }

    public function invoices(): never
    {
        $this->authorize($this->resource, 'create');
        session_write_close();
        $id = trim((string) Request::query('customer'));
        try {
            $c = BillingService::customer($id);
            $rows = $c ? BillingService::invoices($id) : [];
        } catch (\Throwable $e) { error_log('[billing] invoices: '.$e->getMessage()); json_response(['error' => $e->getMessage()], 500); }
        $c || json_response(['error' => __('Customer not found.').' (#'.e($id).')'], 404);
        json_response(['customer' => ['id' => (string) $c['id'], 'name' => (string) $c['name'], 'code' => (string) $c['code'], 'address' => (string) $c['address']], 'rows' => $rows]);
    }

    public function store(): never
    {
        $this->authorize($this->resource, 'create');
        try {
            $id = BillingService::create([
                'customer_id' => trim((string) Request::input('customer_id')), 'invoices' => (array) Request::input('invoices', []), 'address' => (string) Request::input('address', ''),
                'billing_date' => (string) Request::input('billing_date', ''), 'remind_date' => (string) Request::input('remind_date', ''), 'is_thai' => Request::input('lang', 'th') !== 'en',
                'override' => (string) Request::input('override', ''),
            ], $this->user()->id, $this->user()->isAdmin());
        } catch (\Throwable $e) {
            if (Request::isJson()) { json_response(['message' => $e->getMessage()], 422); }
            Session::flash('error', $e->getMessage());
            back();
        }
        $row = DB::first('SELECT billing_number FROM billing_notes WHERE id = ?', [$id], ErpSettings::CONN);
        Activity::log('created', $this->type, $id, (string) $row['billing_number'], ['Created :type ":label"', ['type' => $this->singular, 'label' => $row['billing_number']]], [], null, null, false, 'accounting');
        Session::flash('success', __('Billing note :n created.', ['n' => $row['billing_number']]));
        if (Request::isJson()) { json_response(['ok' => true, 'id' => $id, 'url' => url('/accounting/billing')]); }
        redirect('/accounting/billing');
    }

    /* ------------------------------------------------------------------ view + edit */

    public function show(int $id): string
    {
        $this->authorize($this->resource, 'view');
        $row = $this->find($id);
        $note = BillingService::get($id) ?? abort(404);

        return view('accounting/billing-show', ['title' => (string) $row['billing_number'], 'n' => $note, 'status' => $row['status'], 'canEdit' => can($this->resource, 'edit') && $this->canModify($row, 'edit'),
            'canDelete' => can($this->resource, 'delete'), 'by' => array_column(DB::select('SELECT id, name FROM users WHERE id IN (?, ?)', [(int) $note['created_by'], (int) $note['updated_by']], 'core'), 'name', 'id')]);
    }

    public function edit(int $id): string
    {
        $row = $this->findForChange($id, 'edit');
        $note = BillingService::get($id) ?? abort(404);

        return view('accounting/billing-edit', ['title' => __('Edit :n', ['n' => $row['billing_number']]), 'n' => $note, 'choices' => BillingService::choices($note), 'isAdmin' => $this->user()->isAdmin()]);
    }

    public function update(int $id): never
    {
        $row = $this->findForChange($id, 'edit');
        try {
            BillingService::update($id, [
                'invoices' => (array) Request::input('invoices', []), 'address' => (string) Request::input('address', ''), 'billing_date' => (string) Request::input('billing_date', ''), 'remind_date' => (string) Request::input('remind_date', ''),
                'is_thai' => Request::input('lang', 'th') !== 'en', 'note' => (string) Request::input('note', ''), 'remark' => (string) Request::input('remark', ''), 'billing_number' => (string) Request::input('billing_number', ''),
            ], $this->user()->id, $this->user()->isAdmin());
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            back();
        }
        Activity::log('updated', $this->type, $id, (string) $row['billing_number'], ['Updated :type ":label"', ['type' => $this->singular, 'label' => $row['billing_number']]], ['_url' => url('/accounting/billing/'.$id)], null, null, false, 'accounting');
        Session::flash('success', __('Billing note :n saved. The PDF was built again.', ['n' => $row['billing_number']]));
        redirect('/accounting/billing/'.$id);
    }

    /* ------------------------------------------------------------------ pdf + states */

    public function pdf(int $id): never
    {
        $this->authorize($this->resource, 'view');
        $row = $this->find($id);
        try { $abs = BillingService::pdf($id); }
        catch (\Throwable $e) { abort(500, $e->getMessage()); }
        session_write_close();
        $dl = Request::query('download') === '1';
        header('Content-Type: application/pdf');
        header('Content-Disposition: '.($dl ? 'attachment' : 'inline').'; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $row['billing_number']).'.pdf"');
        header('Content-Length: '.filesize($abs));
        readfile($abs);
        exit;
    }

    public function regenerate(int $id): never
    {
        $this->authorize($this->resource, 'edit');
        $row = $this->find($id);
        try { BillingService::pdf($id, true); Session::flash('success', __('The PDF of :n was built again.', ['n' => $row['billing_number']])); }
        catch (\Throwable $e) { Session::flash('error', $e->getMessage()); }
        redirect('/accounting/billing');
    }

    public function state(int $id, string $to): never
    {
        $this->authorize($this->resource, 'edit');
        $row = $this->find($id);
        $col = $to === 'paid' ? 'is_paid' : ($to === 'complete' ? 'is_complete' : abort(404));
        DB::exec("UPDATE billing_notes SET {$col} = TRUE, updated_by = ?, updated_at = now() WHERE id = ?", [$this->user()->id, $id], ErpSettings::CONN);
        if ($to === 'complete') { DB::exec('UPDATE billing_notes SET is_paid = TRUE WHERE id = ?', [$id], ErpSettings::CONN); }
        Session::flash('success', __(':n marked as :s.', ['n' => $row['billing_number'], 's' => __($to)]));
        redirect('/accounting/billing');
    }

    protected function beforeDelete(array $row): void
    {
        if (! empty($row['file_key'])) { @unlink(BASE_PATH.'/storage/'.$row['file_key']); }
    }
}
