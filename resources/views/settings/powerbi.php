<?php /* $url (current Power BI report link or '') — administrators only */
include __DIR__.'/_tabs.php'; $crumbs = []; ?>
<form method="POST" action="<?= url('/settings/powerbi') ?>" class="panel mt-6 max-w-3xl space-y-4 p-5">
    <?= csrf_field() ?>
    <div>
        <h2 class="panel-title"><?= e(__('Power BI overview report')) ?></h2>
        <p class="mt-1 text-sm text-steel"><?= e(__('Shown full page on Management → Power BI → Overview. Only administrators can change it.')) ?></p>
    </div>
    <div>
        <label for="embed" class="label"><?= e(__('Embed code or report link')) ?></label>
        <textarea id="embed" name="embed" rows="5" class="input font-mono text-xs" placeholder='<iframe title="…" width="1140" height="541.25" src="https://app.powerbi.com/reportEmbed?reportId=…" frameborder="0" allowFullScreen="true"></iframe>'><?= e(old('embed', $url)) ?></textarea>
        <?php if ($err = error_for('embed')): ?><p class="mt-1 text-sm text-signal-700"><?= e($err) ?></p><?php endif ?>
        <p class="hint"><?= e(__('In Power BI: File → Embed report → Website or portal (or Publish to web), copy the iframe code and paste it here. Only the https link to Power BI is kept. Leave empty to remove the report.')) ?></p>
    </div>
    <?php if ($url): ?><p class="break-all text-xs text-steel"><?= e(__('Now showing')) ?>: <?= e($url) ?></p><?php endif ?>
    <div class="flex items-center justify-end gap-2">
        <?php if ($url): ?><a href="<?= url('/management/powerbi') ?>" class="btn-secondary"><?= e(__('Open the report')) ?></a><?php endif ?>
        <button class="btn-primary"><?= e(__('Save')) ?></button>
    </div>
</form>
