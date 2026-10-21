<?php /* $url (current report link or '') — administrators only */ ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Power BI settings')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('The report shown on Management → Power BI → Overview.')) ?></p></div>
    <a href="<?= url('/management/powerbi') ?>" class="btn-secondary"><?= e(__('Back to the report')) ?></a>
</div>

<form method="POST" action="<?= url('/management/powerbi/settings') ?>" class="panel mt-6 max-w-3xl space-y-4 p-5">
    <?= csrf_field() ?>
    <div>
        <label for="embed" class="label"><?= e(__('Embed code or report link')) ?></label>
        <textarea id="embed" name="embed" rows="5" class="input font-mono text-xs" placeholder='<iframe title="…" width="1140" height="541.25" src="https://app.powerbi.com/reportEmbed?reportId=…" frameborder="0" allowFullScreen="true"></iframe>'><?= e(old('embed', $url)) ?></textarea>
        <?php if ($err = error_for('embed')): ?><p class="mt-1 text-sm text-signal-700"><?= e($err) ?></p><?php endif ?>
        <p class="hint"><?= e(__('In Power BI: File → Embed report → Website or portal (or Publish to web), copy the iframe code and paste it here. Only the https link to Power BI is kept. Leave empty to remove the report.')) ?></p>
    </div>
    <?php if ($url): ?><p class="break-all text-xs text-steel"><?= e(__('Now showing')) ?>: <?= e($url) ?></p><?php endif ?>
    <div class="flex justify-end"><button class="btn-primary"><?= e(__('Save')) ?></button></div>
</form>
