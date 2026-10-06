<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;

/**
 * Opportunity stages: the list is fixed (Catalog::STAGES) — what is set up in CRM settings is each stage's
 * colour and which departments may use it. A stage with no "hidden" row for a department is available to it.
 */
final class Stages
{
    /** Colours used until someone changes them. */
    private const DEFAULT_COLORS = [
        'QUALIFICATION' => '#0ea5e9', 'SURVEY_PROPOSAL' => '#8b5cf6', 'EVALUATION_TESTING' => '#f59e0b', 'NEGOTIATION' => '#ea580c',
        'CLOSED_WON' => '#16a34a', 'CLOSED_LOST' => '#dc2626', 'ON_HOLD' => '#64748b', 'CANCEL' => '#94a3b8',
    ];

    private static ?array $colors = null;
    private static array $hidden = [];

    /** [code => '#rrggbb'] */
    public static function colors(): array
    {
        if (self::$colors === null) {
            self::$colors = self::DEFAULT_COLORS;
            try {
                foreach (DB::select('SELECT code, color FROM crm_stages', [], 'crm') as $r) {
                    if (preg_match('/^#[0-9a-f]{6}$/i', (string) $r['color'])) {
                        self::$colors[$r['code']] = strtolower($r['color']);
                    }
                }
            } catch (\Throwable) {
                // table not there yet (before the first migration): defaults are fine
            }
        }

        return self::$colors;
    }

    public static function color(?string $code): string { return self::colors()[$code] ?? '#94a3b8'; }

    public static function forgetCache(): void { self::$colors = null; self::$hidden = []; }

    /** Stage codes a department does NOT use. */
    public static function hiddenFor(?int $departmentId): array
    {
        if (! $departmentId) {
            return [];
        }

        return self::$hidden[$departmentId] ??= array_column(DB::select('SELECT stage FROM stage_hidden WHERE department_id = ?', [$departmentId], 'crm'), 'stage');
    }

    /** Stage codes the person may pick / see in the pipeline, in stage order. Whole-company roles see every stage. */
    public static function availableFor(CurrentUser $u): array
    {
        $all = array_keys(Catalog::STAGES);

        return Access::seesAll($u) ? $all : array_values(array_diff($all, self::hiddenFor($u->department_id)));
    }

    public static function allowed(CurrentUser $u, string $code): bool { return in_array($code, self::availableFor($u), true); }

    /** [code => label] for a dropdown; a record's current stage stays in the list even if its department no longer uses it. */
    public static function options(CurrentUser $u, ?string $current = null): array
    {
        $out = [];
        foreach (self::availableFor($u) as $code) {
            $out[$code] = Catalog::stageLabel($code);
        }
        if ($current && ! isset($out[$current]) && isset(Catalog::STAGES[$current])) {
            $out[$current] = Catalog::stageLabel($current);
        }

        return $out;
    }

    /** Light background tint of a colour, for badges and column tops. */
    public static function tint(string $hex, string $alpha = '22'): string { return $hex.$alpha; }
}
