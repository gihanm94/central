<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

use App\Core\Support\DB;
use App\Modules\Accounting\Erp\ErpSettings;

/** Our own company (the seller on every e-tax document). */
final class Company
{
    public const FIELDS = [
        'name_th' => 'Company name (Thai)', 'name_en' => 'Company name (English)', 'address_th' => 'Address (Thai)', 'address_en' => 'Address (English)',
        'tax_id' => 'Tax ID (13 digits)', 'branch_id' => 'Branch ID (00000 = head office)', 'tax_no' => 'Tax number printed on the PDF', 'tax_info' => 'Tax note',
        'phone' => 'Phone', 'fax' => 'Fax', 'email' => 'E-mail', 'website' => 'Website', 'service_code' => 'INET service code',
        'user_code' => 'INET user code', 'access_key' => 'INET access key', 'api_key' => 'INET API key', 'field_type' => 'Field type',
        'footer_text_th' => 'PDF footer (Thai)', 'footer_text_en' => 'PDF footer (English)',
    ];

    public static function primary(): ?array
    {
        return DB::first('SELECT * FROM companies WHERE is_primary ORDER BY id LIMIT 1', [], ErpSettings::CONN);
    }

    public static function require(): array
    {
        $c = self::primary();
        if (! $c || trim((string) $c['tax_id']) === '') {
            throw new \RuntimeException(__('Fill in the company (tax ID, name, address) under Accounting → Company first.'));
        }

        return $c;
    }

    public static function save(array $in): void
    {
        $row = [];
        foreach (array_keys(self::FIELDS) as $k) { $row[$k] = trim((string) ($in[$k] ?? '')); }
        $row['branch_id'] = $row['branch_id'] !== '' ? $row['branch_id'] : '00000';
        $id = DB::scalar('SELECT id FROM companies WHERE is_primary ORDER BY id LIMIT 1', [], ErpSettings::CONN);
        if ($id) {
            DB::update('companies', $row + ['updated_at' => now()], ['id' => $id], ErpSettings::CONN);
        } else {
            DB::insert('companies', $row + ['is_primary' => true], ErpSettings::CONN);
        }
    }
}
