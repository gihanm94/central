<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Inet\Company;
use App\Modules\Accounting\Inet\PdfRenderer;

/** The seller on every e-tax document, and where the PDF program is. Administrators only. */
class CompanyController extends Controller
{
    private function gate(): void
    {
        if (! $this->user()->isAdmin()) { abort(403, __('Only an administrator can open this.')); }
    }

    public function edit(): string
    {
        $this->gate();

        return view('accounting/company', ['title' => __('Company'), 'c' => Company::primary() ?? [], 'chromium' => PdfRenderer::chromium(), 'chromiumSet' => (string) ErpSettings::get('pdf.chromium', ''), 'scheme' => (string) ErpSettings::get('inet.auth_scheme', 'Bearer')]);
    }

    public function save(): never
    {
        $this->gate();
        $in = Request::all();
        $tax = preg_replace('/\D/', '', (string) ($in['tax_id'] ?? ''));
        if ($tax === '' || strlen($tax) !== 13) { throw new ValidationException(['tax_id' => __('The tax ID has 13 digits.')]); }
        if (trim((string) ($in['name_th'] ?? '')) === '') { throw new ValidationException(['name_th' => __('Enter the company name.')]); }
        $in['tax_id'] = $tax;
        if (! preg_match('/^\d{5}$/', (string) ($in['branch_id'] ?? '00000'))) { throw new ValidationException(['branch_id' => __('The branch ID has 5 digits, e.g. 00000.')]); }
        Company::save($in);
        $chrome = trim((string) ($in['chromium'] ?? ''));
        if ($chrome !== '' && (! is_file($chrome) || ! is_executable($chrome))) { throw new ValidationException(['chromium' => __('That program was not found.')]); }
        ErpSettings::put('pdf.chromium', $chrome, $this->user()->id);
        $scheme = (string) ($in['scheme'] ?? 'Bearer');
        ErpSettings::put('inet.auth_scheme', in_array($scheme, ['Bearer', 'Basic'], true) ? $scheme : 'Bearer', $this->user()->id);
        Session::flash('success', __('Company saved.'));
        redirect('/accounting/company');
    }
}
