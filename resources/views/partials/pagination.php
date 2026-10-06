<?php
/* Footer of every list: first / previous / next / last, rows per page, go to page. $total, $perPage, $page */
use App\Core\Support\Request;
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$page  = min($page, $pages);
$link  = fn ($p) => url(Request::path(), array_merge($_GET, ['page' => $p > 1 ? $p : null]));
$from  = $total ? ($page - 1) * $perPage + 1 : 0;
$to    = min($total, $page * $perPage);
$btn   = function (string $href, string $glyph, string $label, bool $on) {
    $cls = 'inline-flex size-8 items-center justify-center rounded-md ring-1 ring-graphite-900/15 '.($on ? 'bg-white hover:bg-mist' : 'cursor-not-allowed bg-mist/60 text-graphite-400');

    return $on ? '<a href="'.e($href).'" class="'.$cls.'" aria-label="'.e($label).'" title="'.e($label).'">'.$glyph.'</a>' : '<span class="'.$cls.'" aria-disabled="true" aria-label="'.e($label).'">'.$glyph.'</span>';
};
?>
<nav class="flex flex-wrap items-center justify-between gap-3 border-t border-graphite-900/8 px-4 py-3 text-sm" aria-label="<?= e(__('Pages')) ?>">
    <p class="text-steel"><?= e($total ? __(':start–:end of :total', ['start' => $from, 'end' => $to, 'total' => $total]) : __('No rows')) ?></p>
    <form method="GET" action="<?= e(url(Request::path())) ?>" class="flex flex-wrap items-center gap-2">
        <?php foreach ($_GET as $k => $v): if (in_array($k, ['page', 'per_page'], true) || is_array($v)) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach ?>
        <?= $btn($link(1), '«', __('First page'), $page > 1) ?>
        <?= $btn($link($page - 1), '‹', __('Previous'), $page > 1) ?>
        <span class="px-1 tabular-nums text-steel"><?= e(__('Page :p of :n', ['p' => $page, 'n' => $pages])) ?></span>
        <?= $btn($link($page + 1), '›', __('Next'), $page < $pages) ?>
        <?= $btn($link($pages), '»', __('Last page'), $page < $pages) ?>
        <?= select_field('per_page', [10 => '10 / '.__('page'), 20 => '20 / '.__('page'), 50 => '50 / '.__('page'), 100 => '100 / '.__('page')], (string) $perPage, ['submit' => true, 'search' => false, 'wrap' => 'w-28', 'aria' => __('Rows per page'), 'required' => true]) ?>
        <label class="flex items-center gap-2 text-steel"><?= e(__('Go to')) ?>
            <input name="page" type="number" min="1" max="<?= $pages ?>" value="<?= $page ?>" class="input w-16 text-center" aria-label="<?= e(__('Go to page')) ?>"></label>
    </form>
</nav>
