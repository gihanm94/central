<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Request;

/**
 * ERP → Invoices (User Management): the stamped Inet invoices of the person's own department, view and download.
 * The data belongs to the Accounting module and comes through its hooks ('erp.invoices', 'erp.invoice_pdf'),
 * so this page never touches that module's database and simply says "unavailable" when Accounting is off or broken.
 */
class ErpInvoiceController extends Controller
{
    public function index(): string
    {
        $this->authorize('erp_invoices', 'view');
        $u = $this->user();
        $f = ['q' => trim((string) Request::query('q', '')), 'from' => (string) Request::query('from', ''), 'to' => (string) Request::query('to', ''),
            'department' => (int) Request::query('department', 0), 'page' => (int) Request::query('page', 1)];
        $result = Modules::usable('accounting') ? (Modules::hook('erp.invoices', [$u, $f], 'accounting')['accounting'] ?? null) : null;
        $deps = array_column(DB::select('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name'), 'name', 'id');

        return view('erp/invoices', ['title' => __('Invoices'), 'f' => $f, 'result' => $result, 'deps' => $deps,
            'myDept' => $u->department_id ? ($deps[$u->department_id] ?? null) : null, 'isAdmin' => $u->isAdmin()]);
    }

    /** Stream one stamped PDF (only of the person's department; anything else is "not found"). */
    public function pdf(int $id, string|int $doc): never
    {
        $this->authorize('erp_invoices', 'view');
        $u = $this->user();
        $file = Modules::usable('accounting') ? (Modules::hook('erp.invoice_pdf', [$u, $id, (string) $doc], 'accounting')['accounting'] ?? null) : null;
        if (! is_array($file) || ! is_file((string) ($file['path'] ?? ''))) {
            abort(404, __('This invoice is not available.'));
        }
        Activity::log('download', 'inet', $id, (string) $file['label'], ['Downloaded the stamped :doc', ['doc' => $file['label']]], [], null, notify: false);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: '.(Request::query('download') === '1' ? 'attachment' : 'inline').'; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file['name']).'"');
        header('Content-Length: '.filesize($file['path']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file['path']);
        exit;
    }
}
