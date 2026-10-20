<?php /* $row (pdf_signatures + avatar, department_name), $back [label, url], $audit (opened from a log) */
$crumbs = [[$back[0], $back[1]]];
$file = fn (string $k, bool $dl = false) => url('/sign-pdf/'.(int) $row['id'].'/file/'.$k).($dl ? '?download=1' : '');
$places = json_decode((string) $row['placements'], true) ?: [];
$byPage = [];
foreach ($places as $p) { $byPage[(int) $p['page']] = ($byPage[(int) $p['page']] ?? 0) + 1; }
ksort($byPage);
?>
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title break-words"><?= e($row['title']) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Signed by :name on :date', ['name' => $row['user_name'], 'date' => format_date($row['created_at'], 'd M Y, H:i')])) ?></p></div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <a href="<?= e($file('original', true)) ?>" class="btn-secondary flex-1 sm:flex-none"><?= icon('download', 'size-4') ?> <?= e(__('Original')) ?></a>
        <a href="<?= e($file('signed', true)) ?>" class="btn-primary flex-1 sm:flex-none"><?= icon('download', 'size-4') ?> <?= e(__('Signed PDF')) ?></a>
    </div>
</div>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <section class="panel overflow-hidden xl:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Preview')) ?></h2><a href="<?= e($file('signed')) ?>" target="_blank" rel="noopener" class="text-sm text-steel hover:text-graphite-900"><?= e(__('Open in a new tab')) ?> ↗</a></div>
        <div class="h-[75vh] space-y-4 overflow-y-auto bg-graphite-900/5 p-4" data-pdf-preview="<?= e($file('signed')) ?>" data-worker="<?= e(asset('assets/vendor/pdfjs/pdf.worker.min.js')) ?>"
             data-l-failed="<?= e(__('The preview could not be shown. Download the PDF to open it.')) ?>" role="document" aria-label="<?= e(__('Signed PDF')) ?>">
            <p data-pdf-status class="py-10 text-center text-sm text-steel"><?= e(__('Loading the preview…')) ?></p>
        </div>
    </section>
    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Signing record')) ?></h2></div>
        <dl class="divide-y divide-graphite-900/6 text-sm">
            <div class="flex items-center gap-3 px-5 py-3"><?= partial('partials/avatar', ['name' => $row['user_name'], 'avatar' => $row['avatar'] ?? null, 'size' => 'size-9']) ?>
                <div class="min-w-0"><dt class="sr-only"><?= e(__('Signed by')) ?></dt><dd class="truncate font-medium"><?= e($row['user_name']) ?><?= $row['user_id'] ? '' : ' <span class="text-xs text-steel">('.e(__('deleted user')).')</span>' ?></dd>
                    <dd class="truncate text-xs text-steel"><?= e($row['department_name'] ?? '—') ?></dd></div></div>
            <div class="px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Signed')) ?></dt><dd class="mt-0.5 font-medium"><?= e(format_date($row['created_at'], 'l d M Y, H:i:s')) ?></dd></div>
            <div class="px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Signatures')) ?></dt>
                <dd class="mt-0.5 font-medium"><?= count($places) ?> · <?= e(implode(', ', array_map(fn ($p, $n) => __('page :p', ['p' => $p]).($n > 1 ? ' ×'.$n : ''), array_keys($byPage), $byPage))) ?></dd></div>
            <div class="px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Pages')) ?></dt><dd class="mt-0.5 font-medium"><?= (int) $row['pages'] ?></dd></div>
            <div class="px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Signed copy fingerprint (SHA-256)')) ?></dt><dd class="mt-0.5 break-all font-mono text-xs"><?= e($row['signed_sha256']) ?></dd>
                <dd class="mt-0.5 text-xs text-steel"><?= e(App\Core\Support\Files::size((int) $row['signed_size'])) ?></dd></div>
            <div class="px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Original fingerprint (SHA-256)')) ?></dt><dd class="mt-0.5 break-all font-mono text-xs"><?= e($row['original_sha256']) ?></dd>
                <dd class="mt-0.5 text-xs text-steel"><?= e(App\Core\Support\Files::size((int) $row['original_size'])) ?></dd></div>
            <?php if (! empty($audit)): ?>
            <div class="px-5 py-3"><dt class="text-xs text-steel"><?= e(__('From')) ?></dt><dd class="mt-0.5 text-xs"><?= e(($row['ip_address'] ?? '—').' · '.($row['user_agent'] ?? '')) ?></dd></div>
            <?php endif ?>
        </dl>
    </section>
</div>
<script src="<?= asset('assets/vendor/pdfjs/pdf.min.js') ?>"></script>
<script src="<?= asset('assets/pdfview.js') ?>"></script>
