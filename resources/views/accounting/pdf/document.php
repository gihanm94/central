<?php
/*
 * Tax invoice (388) / receipt (T01) / credit note (81) — port of the old Thymeleaf templates (document.html + fragments).
 * Variables: $d doc, $company, $items (rows), $t totals, $thai, $docType, $number, $date, $titles [th, en, noTh, noEn], $theme [bg, head, text, border],
 *            $showShip, $credit (81 only), $fonts (css), $logo, $signature, $footerText, $words, $labels
 */
$layout = false;
$th  = $thai;
$p   = fn (string $a, string $b) => $th ? $a : $b;
$fmt = fn ($v) => number_format((float) $v, 2, '.', ',');
$e   = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!DOCTYPE html>
<html lang="<?= $th ? 'th' : 'en' ?>"><head><meta charset="UTF-8"><title><?= $e('acme-tax-'.$d['invoice_no']) ?></title>
<style><?= $fonts ?></style>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
html{width:210mm}
body{width:210mm;background:#fff;color:var(--text);font-family:'Sarabun',sans-serif;font-size:11px}
.flex{display:flex}.gap-3{gap:10px}.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:8px}.w-full{width:100%}
.text-center{text-align:center}.text-right{text-align:right}.text-left{text-align:left}
.font-medium{font-weight:500}.font-bold{font-weight:700}.font-semibold{font-weight:600}
.uppercase{text-transform:uppercase}.tracking-widest{letter-spacing:.12em}.leading-tight{line-height:1.2}.leading-relaxed{line-height:1.5}
.whitespace-pre{white-space:pre-line}.shrink-0{flex-shrink:0}.nowrap{white-space:nowrap}
.px-2{padding-left:6px;padding-right:6px}.px-3{padding-left:10px;padding-right:10px}.px-5{padding-left:18px;padding-right:18px}
.py-2{padding-top:5px;padding-bottom:5px}.py-3{padding-top:8px;padding-bottom:8px}.mt-1{margin-top:2px}.mt-2{margin-top:5px}
.text-sm{font-size:12px}.text-10px{font-size:10px}.text-11px{font-size:11px}
.c-text{color:var(--text)}.c-head{color:var(--header-text)}.bg-band{background:var(--bg)}.bg-white{background:#fff}
.bd{border:1px solid var(--border)}.bd-r{border-right:1px solid var(--border)}.bd-b{border-bottom:1px solid var(--border)}.bd-dash{border:2px dashed var(--border)}
.h-16{height:110px}.w-16{width:110px}.border-collapse{border-collapse:collapse}
@page{size:A4;margin:0}
.avoid-break{page-break-inside:avoid;break-inside:avoid}
tr{page-break-inside:avoid}
</style></head>
<body style="--header-text:<?= $e($theme['head']) ?>;--text:<?= $e($theme['text']) ?>;--border:<?= $e($theme['border']) ?>;--bg:<?= $e($theme['bg']) ?>;">
<div style="display:flex;flex-direction:column;min-height:297mm;">

<!-- header -->
<div class="flex" style="min-height:108px;background:#fff;align-items:flex-start;margin-top:5mm;">
  <div class="flex gap-3 px-5 py-2" style="align-items:flex-start;width:calc(100% - 250px);">
    <?php if ($logo): ?><img src="<?= $logo ?>" alt="" class="h-16 w-16 shrink-0" style="object-fit:contain;"><?php endif ?>
    <div style="display:flex;flex-direction:column;gap:1px;">
      <div style="width:max-content;max-width:100%;">
        <p class="font-semibold text-sm leading-tight c-text" style="text-align:justify;text-align-last:justify;"><?= $e($company['name_th']) ?></p>
        <?php if (trim((string) $company['name_en']) !== ''): ?><p class="font-semibold text-sm leading-tight c-text" style="text-align:justify;text-align-last:justify;"><?= $e($company['name_en']) ?></p><?php endif ?>
      </div>
      <p class="text-11px leading-tight mt-1 c-text"><?= $e($company['address_th']) ?></p>
      <?php if (trim((string) $company['address_en']) !== ''): ?><p class="text-11px leading-tight c-text"><?= $e($company['address_en']) ?></p><?php endif ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;column-gap:12px;margin-top:1px;">
        <p class="text-11px c-text"><?= $p('โทร/TEL', 'Tel') ?>: <?= $e($company['phone']) ?></p>
        <p class="text-11px c-text" style="text-align:right;"><?= $p('โทรสาร/FAX', 'Fax') ?>: <?= $e($company['fax']) ?></p>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;column-gap:12px;">
        <p class="text-11px c-text"><?= $p('WEB SITE', 'Web') ?>: <?= $e($company['website']) ?></p>
        <p class="text-11px c-text" style="text-align:right;"><?= $p('E-mail', 'Email') ?>: <?= $e($company['email']) ?></p>
      </div>
      <p class="text-11px font-medium c-text" style="text-align:right;">เลขประจำตัวผู้เสียภาษีอากร/TAX NO: <?= $e($company['tax_no'] ?: $company['tax_id']) ?> (<?= $p('สำนักงานใหญ่', 'Head Office') ?>)</p>
    </div>
  </div>
  <div style="width:250px;flex-shrink:0;padding-right:18px;display:flex;flex-direction:column;gap:8px;">
    <div style="text-align:center;">
      <p class="font-semibold leading-tight c-text" style="font-size:13px;"><?= $e($titles[0]) ?></p>
      <p class="font-semibold leading-tight c-text" style="font-size:11px;"><?= $e($titles[1]) ?></p>
    </div>
    <div class="bd" style="border-radius:4px;overflow:hidden;">
      <div class="bd-b" style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text"><?= $e($titles[2]) ?></p><p class="text-10px leading-tight c-text"><?= $e($titles[3]) ?></p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;"><?= $e($number) ?></p></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text">วันที่</p><p class="text-10px leading-tight c-text">DATE</p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;"><?= $e($date) ?></p></div>
      </div>
    </div>
  </div>
</div>
<div class="bg-band" style="height:3px;"></div>

<div class="px-5 py-3 mt-2" style="display:flex;flex-direction:column;gap:7px;flex:1;padding-bottom:82mm;">
  <!-- customer -->
  <div class="bd" style="display:flex;border-radius:6px;overflow:hidden;font-size:10px;">
    <?php foreach ([[$p('รหัสลูกค้า', 'Customer Code'), $d['customer_code'], 0], [$p('ชื่อลูกค้า', 'Customer Name'), $d['customer_name'], 1], [$p('สาขา', 'Branch'), $p('สำนักงานใหญ่', 'Head Office'), 0], [$p('เลขประจำตัวผู้เสียภาษี', 'Tax No'), $d['customer_vat_no'], 2]] as [$l, $v, $k]): ?>
    <div class="bg-white <?= $k === 2 ? '' : 'bd-r' ?>" style="<?= $k === 1 ? 'flex:1;' : '' ?>"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;"><?= $e($l) ?></p><p class="text-11px whitespace-pre leading-relaxed c-text" style="padding:5px 10px;"><?= $e($v) ?></p></div>
    <?php endforeach ?>
  </div>
  <!-- addresses -->
  <div class="grid-2">
    <div class="bd" style="border-radius:6px;overflow:hidden;"><div class="bg-band" style="padding:4px 10px;"><p class="c-head font-semibold text-10px uppercase tracking-widest"><?= $p('ที่อยู่', 'BILL TO') ?></p></div>
      <div class="px-3 py-2 bg-white" style="min-height:48px;"><p class="text-11px whitespace-pre leading-relaxed c-text"><?= $e($d['customer_name']."\n".$d['mailing_address'].(trim((string) $d['phone']) !== '' ? "\n\n".$p('โทร', 'Tel').': '.$d['phone'] : '')) ?></p></div></div>
    <?php if ($showShip): ?>
    <div class="bd" style="border-radius:6px;overflow:hidden;"><div class="bg-band" style="padding:4px 10px;"><p class="c-head font-semibold text-10px uppercase tracking-widest"><?= $p('ที่อยู่จัดส่ง', 'SHIP TO') ?></p></div>
      <div class="px-3 py-2 bg-white" style="min-height:48px;"><p class="text-11px whitespace-pre leading-relaxed c-text"><?= $e($d['customer_name']."\n".$d['delivery_address'].(trim((string) $d['delivery_note_number']) !== '' ? "\n\n".$p('อ้างอิงการส่ง', 'Delivery No.').': '.$d['delivery_note_number'] : '')) ?></p></div></div>
    <?php endif ?>
  </div>
  <!-- order info -->
  <div class="bd" style="display:grid;grid-template-columns:minmax(90px,1.4fr) minmax(80px,1fr) minmax(60px,.8fr) minmax(60px,.8fr) minmax(50px,.7fr) minmax(70px,.9fr) minmax(70px,.9fr) minmax(90px,1.4fr);border-radius:6px;overflow:hidden;">
    <?php $cells = [[$p('คำสั่งซื้อ', 'Order No'), $d['po_number'] ?: 'N/A'], [$p('เลขที่ใบสั่งขาย', 'Sale Order No'), $d['order_number']], [$p('คลังสินค้า', 'Warehouse'), $d['warehouse']], [$p('แผนก', 'Division'), $d['seller_department']],
        [$p('เครดิต (วัน)', 'Credit (Days)'), $d['grace_days']], [$p('วันที่ครบกำหนด', 'Due Date'), $d['due_date']], [$p('วิธีการจัดส่ง', 'Delivery Method'), $d['delivery_by']], [$p('ผู้ขาย', 'Seller'), $d['seller_name']]];
    foreach ($cells as $i => [$l, $v]): ?>
    <div class="bg-white <?= $i < 7 ? 'bd-r' : '' ?>" style="text-align:center;"><p class="text-11px font-medium c-head bg-band nowrap" style="padding:4px 6px;"><?= $e($l) ?></p><p class="text-11px whitespace-pre leading-relaxed c-text" style="padding:5px 6px;"><?= $e($v) ?></p></div>
    <?php endforeach ?>
  </div>
  <!-- lines -->
  <div class="bd" style="border-radius:6px;overflow:hidden;">
    <table class="w-full border-collapse" style="border-spacing:0;font-size:10px;">
      <thead><tr class="c-head bg-band">
        <th class="px-2 py-2 text-center font-semibold text-11px" style="width:24px;"><?= $p('ที่', 'No.') ?></th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:72px;"><?= $p('รหัสสินค้า', 'Item Code') ?></th>
        <th class="px-2 py-2 text-left font-semibold text-11px"><?= $p('รายการ', 'Description') ?></th>
        <th class="px-2 py-2 text-center font-semibold text-11px" style="width:72px;"><?= $p('วันที่จัดส่ง', 'Delivery Date') ?></th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:60px;"><?= $p('จำนวน', 'Qty') ?></th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:70px;"><?= $p('ราคา/หน่วย', 'Unit Price') ?></th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:36px;"><?= $p('ส่วนลด', '%') ?></th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:80px;"><?= $p('จำนวนเงิน', 'Amount') ?></th>
      </tr></thead>
      <tbody>
      <?php if ($credit && ! empty($credit['reference'])): ?>
        <tr class="bg-white"><td></td><td></td><td colspan="6" class="px-2 py-2 text-11px c-text"><?= $p('การเครดิตใบกำกับสินค้าเลขที่', 'Crediting invoice number') ?> <?= $e($credit['reference']) ?> <?= $p('วันที่', 'date') ?> <?= $e($credit['ref_date']) ?></td></tr>
      <?php endif ?>
      <?php foreach ($items as $it): ?>
        <tr class="bg-white">
          <td class="px-2 py-2 text-11px c-text" style="white-space:nowrap;vertical-align:top;"><?= (int) $it['no'] ?></td>
          <td class="px-2 py-2 text-11px c-text" style="white-space:nowrap;vertical-align:top;"><?= $e($it['part_number']) ?></td>
          <td class="px-2 py-2 text-11px c-text" style="vertical-align:top;"><?= $e($it['part_name']) ?></td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><?= $e($it['delivery_date']) ?></td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><?= $fmt($it['qty']) ?> <?= $e($it['unit']) ?></td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><?= $fmt($it['price']) ?></td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><?= $it['discount'] > 0 ? rtrim(rtrim(number_format($it['discount'], 2, '.', ''), '0'), '.') : '-' ?></td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><?= $fmt($it['amount']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php if ($credit && trim((string) ($credit['purpose'] ?? '')) !== ''): ?>
  <div class="bd bg-band avoid-break" style="border-radius:6px;padding:10px;font-size:10px;">
    <p class="font-semibold c-text" style="margin-bottom:4px;"><?= $p('หมายเหตุ', 'Note') ?></p><p class="c-text"><?= $e($credit['purpose']) ?></p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
      <div class="c-text" style="display:flex;justify-content:space-between;"><span><?= $p('มูลค่าใบกำกับภาษีเดิม (ก่อน VAT)', 'Original Amount (Before VAT)') ?></span><span><?= $fmt($credit['original']) ?> (THB)</span></div>
      <div class="c-text" style="display:flex;justify-content:space-between;"><span><?= $p('มูลค่าใบกำกับภาษีที่ถูกต้อง (ก่อน VAT)', 'Corrected Amount (Before VAT)') ?></span><span><?= $fmt($credit['adjusted']) ?> (THB)</span></div>
    </div>
  </div>
  <?php endif ?>

  <!-- footer: notes + signature | totals -->
  <div class="avoid-break" style="position:fixed;left:0;right:0;bottom:10mm;background:#fff;padding:0 18px 4px;display:grid;grid-template-columns:11fr 9fr;gap:10px;align-items:stretch;">
    <div class="bd-dash" style="border-radius:6px;padding:14px;min-height:60px;display:flex;flex-direction:column;justify-content:space-between;">
      <?php if (trim((string) $d['payment_remark']) !== ''): ?><p class="text-11px leading-relaxed c-text" style="white-space:pre-line;"><?= $e($d['payment_remark']) ?></p><?php endif ?>
      <div style="display:flex;gap:28px;margin-top:auto;"><div class="text-center">
        <div style="width:100px;display:flex;flex-direction:column;align-items:center;"><?php if ($signature): ?><img src="<?= $signature ?>" alt="" style="width:80px;height:40px;object-fit:contain;"><?php endif ?><div style="width:100px;border-bottom:1px solid var(--border);"></div></div>
        <p class="text-11px mt-1 c-text"><?= $p('ผู้มีอำนาจลงนาม', 'Authorized Signature') ?></p></div></div>
    </div>
    <div style="display:flex;flex-direction:column;">
      <div class="bd" style="border-radius:6px;overflow:hidden;"><table class="border-collapse w-full">
        <tr class="bg-white"><td class="bd-b" style="padding:5px 10px;font-size:13px;"><span class="text-11px c-text"><?= $p('รวมจำนวนเงิน', 'Subtotal') ?> (THB)</span></td><td class="bd-b text-right c-text" style="padding:5px 10px;font-size:13px;"><?= $fmt($t['basis']) ?></td></tr>
        <tr><td class="bd-b" style="padding:5px 10px;font-size:13px;"><span class="text-11px c-text">VAT <?= rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') ?>% (THB)</span></td><td class="bd-b text-right c-text" style="padding:5px 10px;font-size:13px;"><?= $fmt($t['tax']) ?></td></tr>
        <tr><td class="bd-b" style="padding:5px 10px;font-size:13px;"><span class="text-11px c-text"><?= $p('รวมเงินทั้งสิ้น', 'Total') ?> (THB)</span></td><td class="bd-b text-right c-text" style="padding:5px 10px;font-size:13px;"><?= $fmt($t['grand']) ?></td></tr>
        <tr><td colspan="2" style="padding:5px 10px;font-size:10px;"><span class="text-11px c-text"><?= $e($words) ?></span></td></tr>
      </table></div>
      <div class="bd-dash" style="margin-top:8px;flex:1;border-radius:6px;padding:12px;min-height:48px;"></div>
    </div>
  </div>
</div>
<div style="position:fixed;left:0;right:0;bottom:0;background:var(--bg);padding:3px 18px;text-align:center;font-size:10px;color:var(--header-text);"><?= $e($footerText) ?></div>
</div>
</body></html>
