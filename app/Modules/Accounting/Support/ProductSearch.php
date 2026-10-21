<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Support\DB;

/**
 * Products copied from the ERP, for the CRM opportunity form (hook 'product.search'): search by part number,
 * description or alias, newest first, a page at a time.
 */
final class ProductSearch
{
    /** @return array{items: array<int, array{id: int, part_number: ?string, name: string, price: ?float}>, more: bool} */
    public static function find(string $q, int $page, int $per): array
    {
        $where = []; $params = [];
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $where[] = '(part_number ILIKE ? OR description ILIKE ? OR alias ILIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = DB::select('SELECT id, part_number, description, alias, standard_price FROM erp_products'.($where ? ' WHERE '.implode(' AND ', $where) : '')
            .' ORDER BY part_number, id LIMIT '.($per + 1).' OFFSET '.((max(1, $page) - 1) * $per), $params, 'accounting');

        return ['more' => count($rows) > $per, 'items' => array_map(fn ($r) => [
            'id' => (int) $r['id'], 'part_number' => $r['part_number'], 'name' => (string) ($r['description'] ?: $r['alias'] ?: $r['part_number'] ?: '#'.$r['id']),
            'price' => $r['standard_price'] !== null ? round((float) $r['standard_price'], 2) : null,
        ], array_slice($rows, 0, $per))];
    }
}
