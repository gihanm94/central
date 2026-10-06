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
        $btn  = 'inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs ring-1 ';
        $off  = $btn.'cursor-not-allowed text-graphite-400 ring-graphite-900/10';
        $on   = $btn.'text-graphite-800 ring-graphite-900/20 hover:bg-mist';
        $form = fn (string $path, string $inner, string $extra = '') => '<form method="POST" action="'.e(url($base.$path)).'" class="inline" '.$extra.'>'.csrf_field().$inner.'</form>';

        $sync = $canEdit ? $form('/sync', '<button class="'.$on.'" title="'.e(__('Read this invoice from the ERP again')).'">'.icon('bolt', 'size-3.5').' '.e(__('Sync')).'</button>') : '<span class="'.$off.'">'.icon('bolt', 'size-3.5').' '.e(__('Sync')).'</span>';
        $sync = str_replace('/'.strtolower($type).'/sync', '/sync', $sync);                                  // sync works on the whole invoice

        if (! $canEdit || ! $gen) {
            $send = '<span class="'.$off.'" title="'.e($gen ? '' : __('Generate first')).'">'.icon('send', 'size-3.5').' '.e(__('Send')).'</span>';
        } elseif ($hasPdf) {
            $send = $form('/send', '<button class="'.($sent ? $on : $btn.'bg-signal-600 text-white ring-signal-600 hover:bg-signal-700').'" data-confirm="'.e($sent ? __('Send it to INET again?') : __('Send this document to INET?')).'">'.icon('send', 'size-3.5').' '.e($sent ? __('Resend') : __('Send')).'</button>');
        } else {            // no PDF was made: choose one when sending
            $send = $form('/send', '<label class="'.$btn.'cursor-pointer bg-signal-600 text-white ring-signal-600 hover:bg-signal-700" title="'.e(__('Choose the PDF to send')).'">'.icon('send', 'size-3.5').' '.e(__('Send')).'<input type="file" name="pdf" accept="application/pdf" required class="sr-only" onchange="if (this.files.length && confirm(\''.e(__('Send this document to INET?')).'\')) this.form.submit(); else this.value = \'\'"></label>', 'enctype="multipart/form-data"');
        }
        if ($signed) {
            $inet = '<a href="'.e(url($base.'/file/signed')).'" target="_blank" class="'.$btn.'bg-emerald-50 text-emerald-800 ring-emerald-200 hover:bg-emerald-100" title="'.e(__('Open the signed PDF from INET')).'">'.icon('download', 'size-3.5').' Inet</a>';
        } elseif ($sent && $canEdit) {
            $inet = $form('/fetch', '<button class="'.$on.'" title="'.e(__('Get the signed PDF from INET')).'">'.icon('download', 'size-3.5').' Inet</button>');
        } else {
            $inet = '<span class="'.$off.'">'.icon('download', 'size-3.5').' Inet</span>';
        }
        $menuId = 'dm-'.$r['id'].'-'.strtolower($type).'-'.(++self::$menuSeq);              // the phone list renders the same cells again: ids must differ
        $item   = 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left hover:bg-mist';
        $dead   = 'flex w-full cursor-not-allowed items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-graphite-400';
        $fileUrl = $signed ? $base.'/file/signed' : ($hasPdf ? $base.'/file/pdf' : null);
        $menu = '<button type="button" class="'.$btn.'text-graphite-800 ring-graphite-900/20 hover:bg-mist !px-1.5" data-menu="#'.e($menuId).'" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" aria-label="'.e(__('More')).'">…</button>'
            .'<div id="'.e($menuId).'" data-menu-panel hidden role="menu" class="fixed z-[70] w-44 rounded-lg bg-white p-1.5 text-sm shadow-xl ring-1 ring-graphite-900/10">'
            .($gen ? '<a role="menuitem" href="'.e(url($base.'/file/json')).'" target="_blank" class="'.$item.'"><span class="w-4 text-center font-mono text-emerald-600">{}</span> '.e(__('Raw Data')).'</a>' : '<span class="'.$dead.'"><span class="w-4 text-center font-mono">{}</span> '.e(__('Raw Data')).'</span>')
            .($fileUrl ? '<a role="menuitem" href="'.e(url($fileUrl)).'" target="_blank" class="'.$item.'">'.icon('download', 'size-4 text-sky-600').' '.e(__('Get File')).'</a>' : '<span class="'.$dead.'">'.icon('download', 'size-4').' '.e(__('Get File')).'</span>')
            .(can($this->resource, 'create') ? '<form method="POST" action="'.e(url('/accounting/inet/'.$r['id'].'/regenerate')).'">'.csrf_field().'<button role="menuitem" class="'.$item.'" data-confirm="'.e(__('Generate this invoice again? The text file and PDF are rebuilt and anything sent is marked unsent.')).'">'.icon('bolt', 'size-4 text-amber-500').' '.e(__('Regenerate')).'</button></form>' : '')
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

    /** Generate the chosen invoices. Body (JSON): {items: [{invoice: 123, auto_pdf: true}]} */
    public function generate(): never
    {
        $this->authorize($this->resource, 'create');
        $items = (array) Request::input('items', []);
        if (! $items || count($items) > 200) {
            json_response(['message' => __('Choose between 1 and 200 invoices.')], 422);
        }
        @set_time_limit(600);
        Log::run((string) Request::input('run', ''));
        $by = $this->user()->id;
        session_write_close();                      // the live log is read by other requests meanwhile; do not hold the session lock
        Log::info('inet', 'Generate: '.count($items).' invoice(s) chosen', ['by' => $by]);
        $made = 0;
        $errors = [];
        $items = array_values(array_filter(array_map(fn ($it) => ['invoice' => (int) ($it['invoice'] ?? 0), 'auto_pdf' => ! empty($it['auto_pdf'])], $items), fn ($it) => $it['invoice'] > 0));
        try {
            foreach (Generator::many($items, $by, 5) as $no => $r) {
                if ($r['docs']) { $made++; }
                foreach ($r['errors'] as $type => $m) { $errors[] = $no.($type === '-' ? '' : ' ('.$type.')').': '.$m; }
            }
        } catch (\Throwable $e) {
            Log::exception('inet', $e, 'Generate failed');
            $errors[] = $e->getMessage();
        }
        ($errors ? [Log::class, 'warn'] : [Log::class, 'info'])('inet', 'Generate finished: '.$made.' made, '.count($errors).' with problems', ['done' => true]);
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        if ($made) {
            Activity::log('created', 'inet', null, (string) $made, ['Generated :n e-tax invoice(s)', ['n' => $made]], [], null, null, false, 'accounting');
        }
        Session::flash($errors ? 'error' : 'success', __(':n invoice(s) generated.', ['n' => $made]).($errors ? ' '.__(':n had problems — see the message on each row.', ['n' => count($errors)]) : ''));
        json_response(['ok' => true, 'made' => $made, 'errors' => array_slice($errors, 0, 20)], 200);
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
                Session::flash('error', __('Choose a PDF file (up to 15 MB).'));
                $this->backTo($row);
            }
            $pdf = $bytes;
        }
        try {
            Session::flash('success', InetClient::send($row, strtoupper($doc), $pdf));
        } catch (\Throwable $e) {
            Log::exception('inet', $e, $row['no_invoice'].' '.$doc.' send failed');
            Session::flash('error', $e->getMessage());
        }
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
