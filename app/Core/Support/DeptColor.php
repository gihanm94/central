<?php
declare(strict_types=1);

namespace App\Core\Support;

/** The colour of a department: the one set on the department, else a stable pick from the standard palette. */
final class DeptColor
{
    public const PALETTE = ['#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#ea580c', '#475569', '#be123c'];
    public const NONE = '#9ca3af';

    public static function of(?int $id, ?string $color = null): string
    {
        if ($color && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtolower($color);
        }

        return $id ? self::PALETTE[($id - 1) % count(self::PALETTE)] : self::NONE;
    }
}
