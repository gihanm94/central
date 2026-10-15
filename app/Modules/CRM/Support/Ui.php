<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

/** The shared App\Core\Support\Ui snippets plus the CRM-only ones. */
final class Ui extends \App\Core\Support\Ui
{
    /** Stage name with the colour set for it in CRM settings. */
    public static function stageBadge(?string $stage): string
    {
        $c = Stages::color($stage);

        return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-medium text-graphite-900" style="background:'.e($c).'22"><span class="size-2 shrink-0 rounded-full" style="background:'.e($c).'"></span>'.e(Catalog::stageLabel($stage)).'</span>';
    }
}
