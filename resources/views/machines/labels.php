<?php $layout = false; /* $rows: machine_code, machine_name, department, qr */ ?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(__('QR labels')) ?></title>
<style>
 *{box-sizing:border-box} body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#111;background:#f3f4f6}
 .bar{position:sticky;top:0;display:flex;gap:.75rem;align-items:center;justify-content:space-between;padding:.75rem 1rem;background:#fff;border-bottom:1px solid #ddd}
 .bar button,.bar a{font:inherit;padding:.45rem .9rem;border-radius:.5rem;border:1px solid #bbb;background:#fff;cursor:pointer;text-decoration:none;color:#111}
 .bar .go{background:#d11a2a;border-color:#d11a2a;color:#fff}
 .sheet{display:grid;grid-template-columns:repeat(auto-fill,minmax(7cm,1fr));gap:.5cm;padding:1cm}
 .label{background:#fff;border:1px dashed #999;border-radius:.3cm;padding:.4cm;text-align:center;break-inside:avoid;display:flex;flex-direction:column;align-items:center;gap:.15cm}
 .label .qr{width:4.2cm;height:4.2cm}.label .qr svg{width:100%;height:100%;display:block}
 .label b{font-size:14pt;letter-spacing:.02em}.label span{font-size:9pt;color:#444;line-height:1.25}
 @media print{body{background:#fff}.bar{display:none}.sheet{padding:0;gap:.3cm}.label{border:1px solid #bbb}}
</style></head><body>
<div class="bar"><span><?= e(__(':n labels', ['n' => count($rows)])) ?></span><span><a href="javascript:history.back()"><?= e(__('Back')) ?></a> <button class="go" onclick="window.print()"><?= e(__('Print')) ?></button></span></div>
<div class="sheet">
<?php foreach ($rows as $r): ?>
    <div class="label"><div class="qr" data-qr="<?= e($r['qr']) ?>"></div><b><?= e($r['machine_code']) ?></b><span><?= e($r['machine_name']) ?><?= $r['department'] ? '<br>'.e($r['department']) : '' ?></span></div>
<?php endforeach ?>
</div>
<script src="<?= asset('assets/vendor/qr/qrcode.js') ?>"></script>
<script src="<?= asset('assets/machines.js') ?>"></script>
</body></html>
