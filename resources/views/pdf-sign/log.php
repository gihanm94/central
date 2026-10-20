<?php /* $f {q,user,from,to,page}, $result, $people [id => name], $base (/pdf-sign-log or /management/pdf-signs) */
$link = fn (array $x = []) => url($base, array_filter(array_merge(['q' => $f['q'], 'user' => $f['user'] ?: null, 'from' => $f['from'], 'to' => $f['to']], $x)));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('PDF signing log')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Every PDF signed in User Management → Sign PDF: who signed it, when, and the signed copy to preview.')) ?></p></div>
</div>
<section class="panel mt-6 overflow-hidden">
    <form method="GET" action="<?= url($base) ?>" class="flex flex-wrap items-end gap-2 border-b border-graphite-900/8 p-3">
        <div class="min-w-[12rem] flex-1"><label class="label" for="pl-q"><?= e(__('Search')) ?></label><input id="pl-q" name="q" value="<?= e($f['q']) ?>" class="input" placeholder="<?= e(__('File name or person')) ?>"></div>
        <div class="w-full sm:w-56"><label class="label" for="pl-user"><?= e(__('Person')) ?></label><?= select_field('user', $people, $f['user'] ?: '', ['id' => 'pl-user', 'placeholder' => __('Everyone'), 'search' => true]) ?></div>
        <div><label class="label" for="pl-from"><?= e(__('From')) ?></label><input id="pl-from" type="date" name="from" value="<?= e($f['from']) ?>" class="input"></div>
        <div><label class="label" for="pl-to"><?= e(__('To')) ?></label><input id="pl-to" type="date" name="to" value="<?= e($f['to']) ?>" class="input"></div>
        <button class="btn-secondary"><?= e(__('Show')) ?></button>
        <span class="ml-auto self-center text-sm text-steel"><?= e(__(':n signed PDFs', ['n' => $result['total']])) ?></span>
    </form>
    <?= partial('pdf-sign/rows', ['result' => $result, 'base' => $base, 'showPerson' => true, 'link' => $link]) ?>
</section>
