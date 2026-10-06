<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Support\Log;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Erp\Syncer;
use App\Modules\Accounting\Inet\Documents;
use App\Modules\Accounting\Inet\Generator;
use App\Modules\Accounting\Inet\InetClient;
use App\Modules\Accounting\Inet\Jobs;
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
    private static int $menuSeq = 0;

    /** The list is read-only like the other ERP copies, but an administrator may remove a generated e-tax row (and its files). */
    protected function canModify(array $row, string $action): bool { return $action === 'delete'; }

    protected function meta(): array { return parent::meta() + ['selectable' => can($this->resource, 'delete')]; }

    protected function beforeDelete(array $row): void
    {
        $dir = BASE_PATH.'/storage/inet/'.(int) $row['no_invoice'];
        foreach (glob($dir.'/*') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
        Log::warn('inet', $row['no_invoice'].': e-tax row removed', ['by' => $this->user()->email]);
    }

    /** Build one invoice again (same Auto PDF choice as before). */
    public function regenerate(int $id): never
    {
        $this->authorize($this->resource, 'create');
        $row = DB::first('SELECT * FROM inets WHERE id = ?', [$id], ErpSettings::CONN) ?? abort(404);
        @set_time_limit(300);
        $r = Generator::many([['invoice' => (int) $row['no_invoice'], 'auto_pdf' => filter_var($row['auto_pdf'], FILTER_VALIDATE_BOOL)]], $this->user()->id)[(int) $row['no_invoice']] ?? ['docs' => [], 'errors' => []];
        Session::flash($r['errors'] ? 'error' : 'success', $r['errors'] ? implode(' · ', $r['errors']) : __(':n invoice(s) generated.', ['n' => 1]));
        $this->backTo($row);
    }

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

    /** Sync / Send / Inet buttons of one document type (388, T01 or 81). */
    private function docButtons(array $r, string $type): string
    {
        $c    = Generator::col($type);
        $flag = fn (string $k) => filter_var($r[$k] ?? false, FILTER_VALIDATE_BOOL);
        $gen  = $flag("is_{$c}_generate");
        $sent = $flag("is_{$c}_send");
        $hasPdf  = ! empty($r["pdf_{$c}"]);
        $signed  = ! empty($r["inet_pdf_{$c}"]);
        $msg     = (string) ($r["message_{$c}"] ?? '');
        $canEdit = can($this->resource, 'edit');
        $base    = '/accounting/inet/'.$r['id'].'/'.strtolower($type);
        $btn  = 'inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs font-medium ring-1 ring-inset transition-colors ';
        // soft colours: Sync = amber, Send = red, Inet = green; the same colour but faded when it cannot be used yet
        $tone = ['sync' => ['amber', 'bg-amber-50 text-amber-700 ring-amber-200 hover:bg-amber-100', 'bg-amber-50/60 text-amber-400 ring-amber-100'],
                 'send' => ['red', 'bg-signal-50 text-signal-700 ring-signal-200 hover:bg-signal-100', 'bg-signal-50/60 text-signal-300 ring-signal-100'],
                 'inet' => ['green', 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100', 'bg-emerald-50/60 text-emerald-300 ring-emerald-100']];
        $on   = fn (string $k) => $btn.$tone[$k][1];
        $off  = fn (string $k) => $btn.'cursor-not-allowed '.$tone[$k][2];
        $form = fn (string $path, string $inner, string $extra = '') => '<form method="POST" action="'.e(url($base.$path)).'" class="inline" '.$extra.'>'.csrf_field().$inner.'</form>';

        $sync = $canEdit ? $form('/sync', '<button class="'.$on('sync').'" title="'.e(__('Read this invoice from the ERP again')).'">'.icon('bolt', 'size-3.5').' '.e(__('Sync')).'</button>') : '<span class="'.$off('sync').'">'.icon('bolt', 'size-3.5').' '.e(__('Sync')).'</span>';
        $sync = str_replace('/'.strtolower($type).'/sync', '/sync', $sync);                                  // sync works on the whole invoice

        if (! $canEdit || ! $gen) {
            $send = '<span class="'.$off('send').'" title="'.e($gen ? '' : __('Generate first')).'">'.icon('send', 'size-3.5').' '.e(__('Send')).'</span>';
        } else {            // opens the Send window: upload a PDF, or use the generated one
            $send = '<button type="button" class="'.$on('send').'" data-inet-send data-url="'.e(url($base.'/send')).'" data-pdf="'.($hasPdf ? e(url($base.'/file/pdf')) : '').'" data-title="'.e(($type === '81' ? __('Credit note') : ($type === 'T01' ? __('Receipt') : __('Invoice'))).' #'.$r['no_invoice']).'">'.icon('send', 'size-3.5').' '.e($sent ? __('Resend') : __('Send')).'</button>';
        }
        if ($signed) {
            $inet = '<a href="'.e(url($base.'/file/signed')).'" target="_blank" class="'.$on('inet').'" title="'.e(__('Open the signed PDF from INET')).'">'.icon('download', 'size-3.5').' Inet</a>';
        } elseif ($sent && $canEdit) {
            $inet = $form('/fetch', '<button class="'.$on('inet').'" title="'.e(__('Get the signed PDF from INET')).'">'.icon('download', 'size-3.5').' Inet</button>');
        } else {
            $inet = '<span class="'.$off('inet').'">'.icon('download', 'size-3.5').' Inet</span>';
        }
        $more = $btn.'bg-white text-graphite-700 ring-graphite-900/15 hover:bg-mist !px-1.5';
        $menuId = 'dm-'.$r['id'].'-'.strtolower($type).'-'.(++self::$menuSeq);              // the phone list renders the same cells again: ids must differ
        $item   = 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left hover:bg-mist';
        $dead   = 'flex w-full cursor-not-allowed items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-graphite-400';
        $fileUrl = $signed ? $base.'/file/signed' : ($hasPdf ? $base.'/file/pdf' : null);
        $menu = '<button type="button" class="'.$more.'" data-menu="#'.e($menuId).'" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" aria-label="'.e(__('More')).'">…</button>'
            .'<div id="'.e($menuId).'" data-menu-panel hidden role="menu" class="fixed z-[70] w-44 rounded-lg bg-white p-1.5 text-sm shadow-xl ring-1 ring-graphite-900/10">'
            .($gen ? '<a role="menuitem" href="'.e(url($base.'/file/json')).'" target="_blank" class="'.$item.'"><span class="w-4 text-center font-mono text-emerald-600">{}</span> '.e(__('Raw Data')).'</a>' : '<span class="'.$dead.'"><span class="w-4 text-center font-mono">{}</span> '.e(__('Raw Data')).'</span>')
            .($fileUrl ? '<a role="menuitem" href="'.e(url($fileUrl)).'" target="_blank" class="'.$item.'">'.icon('download', 'size-4 text-sky-600').' '.e(__('Get File')).'</a>' : '<span class="'.$dead.'">'.icon('download', 'size-4').' '.e(__('Get File')).'</span>')
            .(can($this->resource, 'create') ? '<button type="button" role="menuitem" class="'.$item.'" data-inet-regen data-invoice="'.e($r['no_invoice']).'" data-auto="'.(filter_var($r['auto_pdf'] ?? false, FILTER_VALIDATE_BOOL) ? '1' : '0').'" data-confirm-text="'.e(__('Generate this invoice again? The text file and PDF are rebuilt and anything sent is marked unsent.')).'">'.icon('bolt', 'size-4 text-amber-500').' '.e(__('Regenerate')).'</button>' : '')
            .(can($this->resource, 'delete') ? '<hr class="my-1 border-graphite-900/8"><button type="button" role="menuitem" class="'.$item.' text-signal-700 hover:bg-signal-50" data-delete-url="'.e(url('/accounting/inet/'.$r['id'].'/delete')).'" data-delete-name="'.e($r['no_invoice']).'" data-delete-kind="'.e(__('e-tax invoice')).'">'.icon('trash', 'size-4').' '.e(__('Remove')).'</button>' : '')
            .'</div>';
        $links = $menu;
        $warn = $msg !== '' && ! str_starts_with($msg, 'OK') ? '<span class="block max-w-[14rem] truncate text-[11px] text-signal-700" title="'.e($msg).'">'.e($msg).'</span>' : '';

        return '<span class="block"><span class="inline-flex items-center gap-1.5">'.$sync.$send.$inet.''.$links.'</span>'.$warn.'</span>';
    }

    protected function columns(): array
    {
        $cols = [
            'no'       => ['label' => __('Invoice number'), 'primary' => true, 'sort' => 't.no_invoice', 'render' => fn ($r) => '<a href="'.e(url('/accounting/inet/'.$r['id'])).'" class="font-medium hover:text-signal-700">'.e($r['no_invoice']).'</a><span class="block text-xs text-steel">'.e($r['customer_order_number'] ?? '').'</span>'],
            'customer' => ['label' => __('Customer code'), 'sort' => 't.customer_code', 'render' => fn ($r) => '<span title="'.e($r['customer_name']).'">'.e($r['customer_code'] ?: '—').'</span>'],
            'intl'     => ['label' => __('International'), 'sort' => 't.is_international', 'render' => fn ($r) => filter_var($r['is_international'], FILTER_VALIDATE_BOOL) ? Ui::badge(__('Yes'), 'info') : Ui::badge(__('No'), 'neutral')],
            'amount'   => ['label' => __('Amount'), 'sort' => 't.total_amount', 'render' => fn ($r) => self::money($r['total_amount'], $r['currency_code'] !== 'THB' ? $r['currency_code'] : '฿')],
        ];
        $cols['seller'] = ['label' => __('Seller'), 'render' => fn ($r) => e($r['seller_name'] ?: '—')];
        if ($this->credit()) {
            $cols['credit'] = ['label' => __('Credit note'), 'render' => fn ($r) => $this->docButtons($r, '81')];
        } else {
            $cols['invoice'] = ['label' => __('Invoice'), 'render' => fn ($r) => $this->docButtons($r, '388')];
            $cols['receipt'] = ['label' => __('Receipt'), 'render' => fn ($r) => $this->docButtons($r, 'T01')];
        }

        return $cols;
    }

    /* --------------------------------------------------------- tabs + dialog */

    protected function topExtra(): string
    {
        $tab = fn (bool $credit, string $label) => '<a href="'.e(url('/accounting/inet', $credit ? ['credit' => 1] : [])).'" class="rounded-md px-3 py-1.5 text-sm '.($this->credit() === $credit ? 'bg-signal-600 font-medium text-white' : 'text-steel hover:bg-mist').'">'.e($label).'</a>';

        return '<nav class="mt-4 inline-flex gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-graphite-900/10" aria-label="'.e(__('Document type')).'">'.$tab(false, __('Invoice')).$tab(true, __('Credit note')).'</nav>'
            .partial('accounting/inet-dialog', ['credit' => $this->credit()]).partial('accounting/inet-send-dialog');
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
        if (! DB::scalar('SELECT 1 FROM erp_documents LIMIT 1', [], ErpSettings::CONN)) { Documents::refreshQuietly(); }     // first time: prepare everything once
        // one tab = credit notes or invoices; the search only looks inside that tab; invoices already generated are left out
        $where = ['d.is_credit = ?', 'NOT EXISTS (SELECT 1 FROM inets n WHERE n.no_invoice = d.invoice_number)'];
        $par   = [$credit ? 'true' : 'false'];
        foreach (preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $where[] = "d.search LIKE ? ESCAPE '\\'";
            $par[]   = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w).'%';
        }
        $from  = 'FROM erp_documents d WHERE '.implode(' AND ', $where);
        $total = (int) DB::scalar("SELECT count(*) {$from}", $par, ErpSettings::CONN);
        $rows  = DB::select("SELECT d.invoice_number, d.order_number AS order_no, d.vat_no, d.customer_code, d.delivery_no, d.remark, d.payment_remark, d.seller_name, d.missing {$from} ORDER BY d.invoice_number DESC LIMIT ".self::PER.' OFFSET '.(($page - 1) * self::PER), $par, ErpSettings::CONN);

        json_response(['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / self::PER)), 'credit' => $credit]);
    }

    /** Generate the chosen invoices: starts a background job and answers at once with its id (the page then asks /job/{id}). */
    public function generate(): never
    {
        $this->authorize($this->resource, 'create');
        $items = (array) Request::input('items', []);
        if (! $items || count($items) > 200) {
            json_response(['message' => __('Choose between 1 and 200 invoices.')], 422);
        }
        $items = array_values(array_filter(array_map(fn ($it) => ['invoice' => (int) ($it['invoice'] ?? 0), 'auto_pdf' => ! empty($it['auto_pdf'])], $items), fn ($it) => $it['invoice'] > 0));
        $id = Jobs::create($items, $this->user()->id);
        Log::info('inet', 'Generate queued: '.count($items).' invoice(s)', ['job' => $id, 'by' => $this->user()->email]);
        session_write_close();
        if (! Jobs::start($id)) {                    // this server cannot start processes: do it here, the page still gets the finished job
            Jobs::run($id);
        }
        json_response(['job' => $id]);
    }

    /** How far a Generate job is (polled once a second). */
    public function job(string $id): never
    {
        $this->authorize($this->resource, 'create');
        session_write_close();
        $j = Jobs::read($id) ?? abort(404);
        json_response(array_intersect_key($j, array_flip(['id', 'status', 'total', 'built', 'printed', 'saved', 'made', 'errors', 'message'])) + ['printed' => 0]);
    }

    /** Read the invoice (and its order rows) from the ERP again and refresh the figures. */
    public function sync(int $id): never
    {
        $this->authorize($this->resource, 'edit');
        $row = DB::first('SELECT * FROM inets WHERE id = ?', [$id], ErpSettings::CONN) ?? abort(404);
        try {
            $s = new Syncer();
            $s->run('invoice', 'all', null, null, 'InvoiceNumber eq '.(int) $row['no_invoice'], true);
            if ($row['order_id']) {
                $s->run('order', 'one', (string) $row['order_id']);
                $s->run('order_row', 'all', null, null, "ParentOrderId eq '".(int) $row['order_id']."'", true);
            }
            InetBuilder::save((int) $row['no_invoice'], ! filter_var($row['is_manual'], FILTER_VALIDATE_BOOL), $this->user()->id);
            Session::flash('success', __('Invoice :no read from the ERP again.', ['no' => $row['no_invoice']]));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect('/accounting/inet'.($row['is_credit'] === true || $row['is_credit'] === 't' ? '?credit=1' : ''));
    }

    private function docRow(int $id, string|int $doc): array
    {
        $doc = (string) $doc;
        $row = DB::first('SELECT * FROM inets WHERE id = ?', [$id], ErpSettings::CONN) ?? abort(404);
        in_array(strtolower($doc), ['388', 't01', '81'], true) || abort(404);

        return $row;
    }

    private function backTo(array $row): never
    {
        redirect('/accounting/inet'.(filter_var($row['is_credit'], FILTER_VALIDATE_BOOL) ? '?credit=1' : ''));
    }

    /** Send the text and PDF of one document to INET (a PDF can be chosen when none was generated). */
    public function send(int $id, string|int $doc): never
    {
        $doc = (string) $doc;
        $this->authorize($this->resource, 'edit');
        $row  = $this->docRow($id, $doc);
        $pdf  = null;
        if ($f = Request::file('pdf')) {
            $bytes = ($f['error'] ?? 1) === UPLOAD_ERR_OK ? (string) file_get_contents($f['tmp_name']) : '';
            if (! str_starts_with($bytes, '%PDF') || strlen($bytes) > 15 * 1024 * 1024) {
                if (Request::isJson()) { json_response(['ok' => false, 'message' => __('Choose a PDF file (up to 15 MB).')]); }
                Session::flash('error', __('Choose a PDF file (up to 15 MB).'));
                $this->backTo($row);
            }
            $pdf = $bytes;
        }
        $ok = true;
        try {
            $msg = InetClient::send($row, strtoupper($doc), $pdf);
        } catch (\Throwable $e) {
            Log::exception('inet', $e, $row['no_invoice'].' '.$doc.' send failed');
            $ok = false;
            $msg = $e->getMessage();
        }
        Session::flash($ok ? 'success' : 'error', $msg);
        if (Request::isJson()) { json_response(['ok' => $ok, 'message' => $msg]); }
        $this->backTo($row);
    }

    /** Fetch the signed PDF from INET. */
    public function fetch(int $id, string|int $doc): never
    {
        $doc = (string) $doc;
        $this->authorize($this->resource, 'edit');
        $row = $this->docRow($id, $doc);
        try {
            Session::flash('success', InetClient::fetch($row, strtoupper($doc)));
        } catch (\Throwable $e) {
            Log::exception('inet', $e, $row['no_invoice'].' '.$doc.' fetch failed');
            Session::flash('error', $e->getMessage());
        }
        $this->backTo($row);
    }

    /** Show the text file (json), the generated PDF (pdf) or the signed PDF from INET (signed). */
    public function file(int $id, string|int $doc, string $kind): never
    {
        $doc = (string) $doc;
        $this->authorize($this->resource, 'view');
        $row = $this->docRow($id, $doc);
        $c   = Generator::col($doc);
        if ($kind === 'json') {
            $t = (string) ($row["text_{$c}"] ?? '');
            $t !== '' || abort(404);
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: inline; filename="'.$row['no_invoice'].'_'.strtoupper($doc).'.json"');
            echo $t;
            exit;
        }
        $bytes = Generator::read($row[$kind === 'signed' ? "inet_pdf_{$c}" : "pdf_{$c}"] ?? null) ?? abort(404);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.$row['no_invoice'].'_'.strtoupper($doc).($kind === 'signed' ? '_signed' : '').'.pdf"');
        echo $bytes;
        exit;
    }
}
