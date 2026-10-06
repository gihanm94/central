<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

/** Small HTML snippets shared by the CRM screens (everything is escaped here). */
final class Ui
{
    private const TONES = [
        'neutral' => 'bg-graphite-900/6 text-graphite-800',
        'success' => 'bg-emerald-50 text-emerald-800',
        'warn'    => 'bg-amber-50 text-amber-800',
        'danger'  => 'bg-signal-50 text-signal-800',
        'info'    => 'bg-sky-50 text-sky-800',
        'violet'  => 'bg-violet-50 text-violet-800',
    ];

    public static function badge(string $text, string $tone = 'neutral'): string
    {
        return '<span class="badge '.(self::TONES[$tone] ?? self::TONES['neutral']).'">'.e($text).'</span>';
    }

    /** Pill with the colour of an industry / lead source as a dot. */
    public static function chip(string $text, ?string $color = null): string
    {
        $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $color) ? $color : '#8a909a';

        return '<span class="inline-flex items-center gap-1.5 rounded-full bg-mist px-2 py-0.5 text-xs font-medium text-graphite-800"><span class="size-2 rounded-full" style="background:'.e($color).'"></span>'.e($text).'</span>';
    }

    public static function chips(array $items): string
    {
        return $items ? '<span class="flex flex-wrap gap-1">'.implode('', array_map(fn ($i) => self::chip($i['name'], $i['color'] ?? null), $items)).'</span>' : '<span class="text-graphite-400">—</span>';
    }

    public static function money(mixed $amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return e(number_format((float) $amount, 2).($currency ? ' '.$currency : ''));
    }

    /** 1 234 567 → 1.2M (chart labels). */
    public static function compact(float $n): string
    {
        $a = abs($n);
        foreach ([[1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$div, $sfx]) {
            if ($a >= $div) {
                return rtrim(rtrim(number_format($n / $div, 1), '0'), '.').$sfx;
            }
        }

        return number_format($n, 0);
    }

    /** Stage name with the colour set for it in CRM settings. */
    public static function stageBadge(?string $stage): string
    {
        $c = Stages::color($stage);

        return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-medium text-graphite-900" style="background:'.e($c).'22"><span class="size-2 shrink-0 rounded-full" style="background:'.e($c).'"></span>'.e(Catalog::stageLabel($stage)).'</span>';
    }

    /** Avatar (picture or initial) with a name and a small second line — the first cell of most lists. */
    public static function person(string $name, ?string $sub = null, ?string $img = null, ?string $href = null, string $shape = 'rounded-md'): string
    {
        $pic = $img
            ? '<img src="'.e(upload_url($img)).'" alt="" class="size-9 shrink-0 '.$shape.' bg-white object-contain ring-1 ring-graphite-900/10">'
            : '<span class="inline-flex size-9 shrink-0 items-center justify-center '.$shape.' bg-graphite-900/6 text-xs font-semibold text-graphite-700">'.e(initials($name)).'</span>';
        $title = $href ? '<a href="'.e(url($href)).'" class="block truncate font-medium hover:text-signal-700">'.e($name).'</a>' : '<span class="block truncate font-medium">'.e($name).'</span>';

        return '<div class="flex min-w-0 max-w-[15rem] items-center gap-3">'.$pic.'<div class="min-w-0">'.$title.($sub ? '<span class="block truncate text-xs text-steel">'.e($sub).'</span>' : '').'</div></div>';
    }

    public static function link(string $href, string $text, string $class = 'font-medium hover:text-signal-700'): string
    {
        return '<a href="'.e(url($href)).'" class="'.$class.'">'.e($text).'</a>';
    }

    public static function dash(): string { return '<span class="text-graphite-400">—</span>'; }
}
