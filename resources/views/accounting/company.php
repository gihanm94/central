<?php
use App\Modules\Accounting\Inet\Company;
/* $c company row, $chromium found program, $chromiumSet saved path, $scheme */
$wide = ['address_th', 'address_en', 'tax_info', 'footer_text_th', 'footer_text_en', 'payment_note_th', 'payment_note_en', 'receipt_note_th', 'receipt_note_en', 'credit_note_th', 'credit_note_en'];
$secret = ['access_key', 'api_key'];
?>
<div class="min-w-0">
    <p class="text-sm text-steel"><a href="<?= url('/accounting') ?>" class="hover:text-signal-700"><?= e(__('Accounting')) ?></a> › <?= e(__('Setup')) ?></p>
    <h1 class="page-title"><?= e(__('Company')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('The seller printed on every e-tax invoice, receipt and credit note, and written in the INET text file.')) ?></p>
</div>
<form method="POST" action="<?= url('/accounting/company') ?>" class="panel mt-4">
    <?= csrf_field() ?>
    <div class="grid gap-4 p-5 sm:grid-cols-2">
        <?php foreach (Company::FIELDS as $k => $label): ?>
        <div class="<?= in_array($k, $wide, true) ? 'sm:col-span-2' : '' ?>">
            <label class="label" for="co-<?= $k ?>"><?= e(__($label)) ?><?= in_array($k, ['tax_id', 'name_th'], true) ? ' <span class="text-signal-600">*</span>' : '' ?></label>
            <?php if (in_array($k, $wide, true)): ?><textarea id="co-<?= $k ?>" name="<?= $k ?>" rows="2" class="input"><?= e(old($k, $c[$k] ?? '')) ?></textarea>
            <?php else: ?><input id="co-<?= $k ?>" name="<?= $k ?>" value="<?= e(old($k, $c[$k] ?? ($k === 'branch_id' ? '00000' : ''))) ?>" class="input" <?= in_array($k, $secret, true) ? 'autocomplete="off"' : '' ?>><?php endif ?>
            <?= field_error($k) ?>
        </div>
        <?php endforeach ?>
    </div>
    <div class="panel-head border-t border-graphite-900/8"><h2 class="panel-title"><?= e(__('PDF and INET')) ?></h2></div>
    <div class="grid gap-4 p-5 sm:grid-cols-2">
        <div class="sm:col-span-2"><label class="label" for="co-chromium"><?= e(__('Chrome / Chromium program (for the PDF)')) ?></label>
            <input id="co-chromium" name="chromium" value="<?= e(old('chromium', $chromiumSet)) ?>" class="input font-mono text-xs" placeholder="/usr/bin/chromium"><?= field_error('chromium') ?>
            <p class="hint"><?= $chromium ? e(__('Found: :p', ['p' => $chromium])) : e(__('Not found. Install Chromium on the server and enter its path.')) ?></p></div>
        <div><label class="label" for="co-scheme"><?= e(__('Authorization header starts with')) ?></label>
            <select id="co-scheme" name="scheme" class="input"><option value="Bearer" <?= $scheme === 'Bearer' ? 'selected' : '' ?>>Bearer</option><option value="Basic" <?= $scheme === 'Basic' ? 'selected' : '' ?>>Basic</option></select>
            <p class="hint"><?= e(__('Used when the key under ERP connection does not already start with Bearer or Basic.')) ?></p></div>
    </div>
    <div class="flex justify-end border-t border-graphite-900/8 bg-mist/50 px-5 py-3"><button class="btn-primary"><?= e(__('Save company')) ?></button></div>
</form>
