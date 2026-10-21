<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Ui;

/**
 * Vouchers copied from the ERP. The voucher sync expands Comment, Rows and AccountsPayable, so a voucher's rows,
 * its accounts-payable invoice and its comment are all here; the detail page shows them together.
 */
class VoucherController extends ErpListController
{
    protected ?string $syncKey = 'voucher';
    protected string $resource = 'accounting_vouchers';
    protected string $table = 'erp_vouchers';
    protected string $type = 'voucher';
    protected string $singular = 'voucher';
    protected string $plural = 'vouchers';
    protected string $base = '/accounting/vouchers';
    protected string $icon = 'doc';
    protected string $orderBy = 't.voucher_date DESC NULLS LAST, t.id DESC';

    protected function select(): string
    {
        return "SELECT t.*, c.raw_text AS comment, p.invoice_number AS payable_invoice, p.suppliers_invoice_number AS payable_supplier_invoice,
                       p.invoice_amount AS payable_amount, p.due_date AS payable_due,
                       (SELECT count(*) FROM erp_voucher_rows r WHERE r.voucher_id = t.id) AS row_count,
                       (SELECT coalesce(sum(r.debit_in_company_currency), 0) FROM erp_voucher_rows r WHERE r.voucher_id = t.id) AS debit_total,
                       (SELECT coalesce(sum(r.credit_in_company_currency), 0) FROM erp_voucher_rows r WHERE r.voucher_id = t.id) AS credit_total
                  FROM erp_vouchers t LEFT JOIN erp_comments c ON c.id = t.comment_id LEFT JOIN erp_payables p ON p.id = t.accounts_payable_id";
    }

    protected function label(array $row): string { return (string) ($row['number'] ?: '#'.$row['id']); }
    protected function searchable(): array { return ['t.number', 't.text', 'p.invoice_number', 'p.suppliers_invoice_number', 'c.raw_text']; }

    protected function filters(): array
    {
        $years = array_map('strval', array_column(DB::select("SELECT DISTINCT extract(year FROM voucher_date)::int AS y FROM erp_vouchers WHERE voucher_date IS NOT NULL ORDER BY 1 DESC", [], $this->conn), 'y'));

        return [
            'year'    => ['label' => __('Year'), 'options' => array_combine($years, $years) ?: [], 'sql' => 'extract(year FROM t.voucher_date)::int = ?::int'],
            'payable' => ['label' => __('Accounts payable'), 'options' => ['1' => __('With a payable invoice'), '0' => __('Without')], 'sql' => '(t.accounts_payable_id IS NOT NULL) = (?::int = 1)'],
            'prelim'  => ['label' => __('Preliminary'), 'options' => ['1' => __('Preliminary'), '0' => __('Final')], 'sql' => 'coalesce(t.is_preliminary, false) = (?::int = 1)'],
        ];
    }

    protected function detail(): array
    {
        return ['number' => ['Voucher number', 'text'], 'voucher_date' => ['Voucher date', 'date'], 'text' => ['Text', 'text'], 'series_id' => ['Series', 'text'],
            'accounting_year_id' => ['Accounting year', 'text'], 'is_preliminary' => ['Preliminary', 'checkbox'], 'bundle_number' => ['Bundle number', 'text'],
            'connected_voucher_id' => ['Connected voucher', 'text'], 'connection_type' => ['Connection type', 'text'],
            'reverse_connection_voucher_id' => ['Reverse connection voucher', 'text'], 'reverse_connection_type' => ['Reverse connection type', 'text'],
            'accounts_payable_id' => ['Accounts payable', 'text'], 'accounts_receivable_id' => ['Accounts receivable', 'text'], 'invoice_log_ledger_id' => ['Invoice log ledger', 'text'],
            'row_count' => ['Rows', 'number'], 'debit_total' => ['Debit (THB)', 'number'], 'credit_total' => ['Credit (THB)', 'number']];
    }

    protected function columns(): array
    {
        return [
            'number'  => ['label' => __('Voucher'), 'primary' => true, 'sort' => 't.number', 'render' => fn ($r) => Ui::person((string) ($r['number'] ?: '#'.$r['id']), self::shortDate($r['voucher_date']), null, '/accounting/vouchers/'.$r['id'])],
            'date'    => ['label' => __('Date'), 'sort' => 't.voucher_date', 'render' => fn ($r) => self::date($r['voucher_date'])],
            'text'    => ['label' => __('Text'), 'sort' => 't.text', 'render' => fn ($r) => '<span class="block max-w-sm truncate" title="'.e((string) $r['text']).'">'.e($r['text'] ?: '—').'</span>'
                .($r['comment'] ? '<span class="block max-w-sm truncate text-xs text-steel">'.e((string) $r['comment']).'</span>' : '')],
            'payable' => ['label' => __('Accounts payable'), 'sort' => 'p.invoice_number', 'render' => fn ($r) => $r['accounts_payable_id']
                ? e((string) ($r['payable_supplier_invoice'] ?: $r['payable_invoice'] ?: '#'.$r['accounts_payable_id'])).($r['payable_amount'] !== null ? '<span class="block text-xs text-steel tabular-nums">'.e(number_format((float) $r['payable_amount'], 2)).'</span>' : '')
                : '<span class="text-graphite-400">—</span>'],
            'rows'    => ['label' => __('Rows'), 'sort' => 'row_count', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['row_count'].'</span>'],
            'debit'   => ['label' => __('Debit'), 'sort' => 'debit_total', 'render' => fn ($r) => self::money($r['debit_total'])],
            'credit'  => ['label' => __('Credit'), 'sort' => 'credit_total', 'render' => fn ($r) => self::money($r['credit_total'])],
            'state'   => ['label' => __('Status'), 'render' => fn ($r) => filter_var($r['is_preliminary'], FILTER_VALIDATE_BOOL) ? Ui::badge(__('Preliminary'), 'warn') : Ui::badge(__('Final'), 'neutral')],
        ];
    }

    private static function shortDate(?string $v): string { return $v ? format_date($v, 'd M Y') : ''; }

    /** Rows, the accounts-payable invoice and the comment of the voucher. */
    protected function showExtra(array $row): string
    {
        $rows = DB::select('SELECT * FROM erp_voucher_rows WHERE voucher_id = ? ORDER BY id', [(int) $row['id']], $this->conn);
        $payable = $row['accounts_payable_id'] ? DB::first('SELECT * FROM erp_payables WHERE id = ?', [(int) $row['accounts_payable_id']], $this->conn) : null;

        return partial('accounting/voucher-detail', ['voucher' => $row, 'rows' => $rows, 'payable' => $payable]);
    }
}
