<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Http\Controllers\ResourceController;
use App\Modules\Machines\Support\Mx;

/** Base of the Machine Checklist list/form screens: rows live in machine_db, the audit columns are filled in here. */
abstract class MachinesController extends ResourceController
{
    protected string $conn = 'machines';
    protected string $module = 'machines';
    protected string $scopeAlias = 't';

    protected function scopeSql(): array { return ['sql' => 'TRUE', 'params' => []]; }

    protected function stamp(array $data, ?array $existing): array
    {
        $data['updated_by'] = $this->user()->id;
        if (! $existing) {
            $data['created_by'] = $this->user()->id;
        }

        return $data;
    }

    /** Names of the people behind *_id columns, looked up in user_db. */
    protected function withPeople(array $rows, array $cols): array
    {
        $ids = [];
        foreach ($cols as $c) {
            $ids = array_merge($ids, array_column($rows, $c));
        }
        $names = Mx::names($ids);
        foreach ($rows as &$r) {
            foreach ($cols as $c) {
                $r[$c.'_name'] = $names[$r[$c] ?? 0] ?? null;
            }
        }

        return $rows;
    }
}
