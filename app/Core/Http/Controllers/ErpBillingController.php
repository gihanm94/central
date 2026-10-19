<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Request;

/**
 * ERP → Billing notes (User Management): the billing notes of the person's own department, view and download.
 * Like ERP → Invoices, the data comes from the Accounting module's hooks ('erp.billing', 'erp.billing_pdf').
 */
class ErpBillingController extends Controller
{
    public function index(): string
    {
        $this->authorize('erp_billing', 'view');
        $u = $this->user();
        $f = ['q' => trim((string) Request::query('q', '')), 'from' => (string) Request::query('from', ''), 'to' => (string) Request::query('to', ''),
            'department' => (int) Request::query('department', 0), 'page' => (int) Request::query('page', 1)];
        $result = Modules::usable('accounting') ? (Modules::hook('erp.billing', [$u, $f], 'accounting')['accounting'] ?? null) : null;
        $deps = array_column(DB::select('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name'), 'name', 'id');

        return view('erp/billing', ['title' => __('Billing notes'), 'f' => $f, 'result' => $result, 'deps' => $deps,
            'myDept' => $u->department_id ? ($deps[$u->department_id] ?? null) : null, 'isAdmin' => $u->isAdmin()]);
    }

    /** Stream one billing note PDF (only of the person's department; anything else is "not found"). */
    public function pdf(int $id): never
    {
        $this->authorize('erp_billing', 'view');
        $file = Modules::usable('accounting') ? (Modules::hook('erp.billing_pdf', [$this->user(), $id], 'accounting')['accounting'] ?? null) : null;
        if (! is_array($file) || ! is_file((string) ($file['path'] ?? ''))) {
            abort(404, __('This billing note is not available.'));
        }
        Activity::log('download', 'billing_note', $id, (string) $file['label'], ['Downloaded billing note :doc', ['doc' => $file['label']]], [], null, notify: false);
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
