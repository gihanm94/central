<?php $layout = null; ?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title><?= e($subject) ?></title></head>
<body style="margin:0;background:#eef0f2;font-family:'IBM Plex Sans','IBM Plex Sans Thai',Segoe UI,Helvetica,Arial,sans-serif;color:#17181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f2;padding:32px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;">
    <tr><td style="background:#17181b;padding:18px 28px;border-bottom:4px solid #d11a2a;">
      <?php if ($brand['logo'] && ! str_ends_with((string) $brand['logo'], '.svg')): ?>
        <img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>" height="32" style="display:block;max-height:32px;">
      <?php else: ?>
        <span style="color:#fff;font-size:20px;font-weight:700;letter-spacing:-.2px;"><?= e($brand['name']) ?></span>
      <?php endif ?>
    </td></tr>
    <tr><td style="padding:28px;">
      <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?= e($subject) ?></h1>
      <p style="margin:0 0 12px;font-size:15px;"><?= e(__('Hello :name,', ['name' => $name])) ?></p>
      <?php foreach ($lines as $line): ?>
        <p style="margin:0 0 10px;font-size:15px;line-height:1.55;color:#2d3036;"><?= $line ?></p>
      <?php endforeach ?>
      <?php if ($button): ?>
        <p style="margin:24px 0;"><a href="<?= e($button['url']) ?>" style="background:#d11a2a;color:#fff;text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:600;font-size:14px;display:inline-block;"><?= e($button['label']) ?></a></p>
      <?php endif ?>
      <?php if ($note): ?><p style="margin:20px 0 0;font-size:13px;color:#5b616b;"><?= e($note) ?></p><?php endif ?>
    </td></tr>
    <tr><td style="padding:16px 28px;background:#f6f7f8;font-size:12px;color:#5b616b;"><?= e(__('Sent automatically by :company.', ['company' => $brand['name']])) ?></td></tr>
  </table>
</td></tr></table>
</body></html>
