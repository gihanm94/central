<?php
/* Billing note (ใบวางบิล) — port of the old billing templates. Variables: $n note, $rows, $company, $thai, $banks, $total, $dfmt, $symbol, $fonts, $logo, $payNote */
$layout = false;
$th  = $thai;
$p   = fn (string $a, string $b) => $th ? $a : $b;
$fmt = fn ($v) => number_format((float) $v, 2, '.', ',');
$e   = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!DOCTYPE html>
<html lang="<?= $th ? 'th' : 'en' ?>"><head><meta charset="UTF-8"><title><?= $e($n['billing_number']) ?></title>
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
<body style="--header-text:#333333;--text:#1a1a1a;--border:#CCCCCC;--bg:#F2F2F2;">
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
      <p class="font-semibold leading-tight c-text" style="font-size:13px;"><?= $p('ใบวางบิล', 'BILLING NOTE') ?></p>
      <p class="font-semibold leading-tight c-text" style="font-size:11px;">BILLING NOTE</p>
    </div>
    <div class="bd" style="border-radius:4px;overflow:hidden;">
      <div class="bd-b" style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text">เลขที่ใบวางบิล</p><p class="text-10px leading-tight c-text">BIL.NO.</p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;"><?= $e($n['billing_number']) ?></p></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text">วันที่</p><p class="text-10px leading-tight c-text">DATE</p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;"><?= $e($dfmt($n['billing_at'])) ?></p></div>
      </div>
    </div>
  </div>
</div>
<div class="bg-band" style="height:3px;"></div>
<div class="px-5 py-3 mt-2" style="display:flex;flex-direction:column;gap:7px;flex:1;padding-bottom:70mm;">
  <div class="bd" style="display:flex;border-radius:6px;overflow:hidden;font-size:10px;">
    <div class="bg-white bd-r"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;"><?= $p('รหัสลูกค้า', 'Customer Code') ?></p><p class="text-11px c-text" style="padding:5px 10px;"><?= $e($n['customer_code']) ?></p></div>
    <div class="bg-white bd-r" style="flex:1;"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;"><?= $p('ชื่อลูกค้า', 'Customer Name') ?></p><p class="text-11px c-text" style="padding:5px 10px;"><?= $e($n['customer_name']) ?></p></div>
    <div class="bg-white"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;"><?= $p('เลขประจำตัวผู้เสียภาษี', 'Tax No') ?></p><p class="text-11px c-text" style="padding:5px 10px;"><?= $e($n['vat_number']) ?></p></div>
  </div>
  <div class="bd" style="border-radius:6px;overflow:hidden;"><div class="bg-band" style="padding:4px 10px;"><p class="c-head font-semibold text-10px uppercase tracking-widest"><?= $p('ที่อยู่', 'BILL TO') ?></p></div>
    <div class="px-3 py-2 bg-white" style="min-height:48px;"><p class="text-11px whitespace-pre leading-relaxed c-text"><?= $e(str_replace(', ', ",\n", str_replace('\\n', "\n", (string) $n['address']))) ?></p></div></div>
  <div class="bd" style="border-radius:6px;overflow:hidden;">
    <table class="w-full border-collapse" style="border-spacing:0;font-size:10px;">
      <thead><tr class="c-head bg-band">
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;"><?= $p('เลขที่ใบแจ้งหนี้', 'Invoice number') ?></th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;"><?= $p('เลขที่คำสั่งซื้อ', 'Order number') ?></th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;"><?= $p('วันที่ใบแจ้งหนี้', 'Invoice date') ?></th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;"><?= $p('วันครบกำหนด', 'Due date') ?></th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:100px;"><?= $p('จำนวนเงิน', 'Amount') ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $cr = filter_var($r['is_credit'], FILTER_VALIDATE_BOOL); ?>
        <tr class="bg-white">
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;"><?= $e($r['invoice_number']) ?></td>
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;"><?= $e($r['order_no']) ?></td>
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;"><?= $e($dfmt($r['invoice_date'])) ?></td>
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;"><?= $e($dfmt($r['due_date'])) ?></td>
          <td class="px-2 py-2 text-11px text-right c-text" style="white-space:nowrap;vertical-align:top;"><?= $cr ? '-' : '' ?><?= $fmt($r['amount']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <div class="flex" style="gap:10px;margin-top:4px;align-items:stretch;">
    <div class="flex" style="gap:10px;flex:0 0 auto;">
      <?php foreach ($banks as [$bn, $br, $ty, $no]): ?>
      <div class="bd bg-white px-3 py-2" style="border-radius:6px;width:150px;"><div class="font-semibold c-text text-11px"><?= $e($bn) ?></div><div class="c-text text-10px mt-1"><?= $e($br.' · '.$ty) ?></div><div class="c-text text-11px mt-1"><?= $e($no) ?></div></div>
      <?php endforeach ?>
    </div>
    <div style="flex:1 1 auto;"></div>
    <div class="bd bg-white px-5 py-3 text-right shrink-0" style="border-radius:6px;min-width:170px;display:flex;flex-direction:column;justify-content:center;">
      <div class="c-text text-10px"><?= $p('รวมเงินทั้งสิ้น', 'Total') ?></div>
      <div class="font-semibold c-text" style="font-size:18px;margin-top:4px;"><?= $e($symbol) ?><?= $fmt($total) ?></div>
    </div>
  </div>
  <div class="avoid-break" style="position:fixed;left:0;right:0;bottom:10mm;background:#fff;padding:0 18px 4px;">
    <?php if (trim($payNote) !== ''): ?><div style="width:100%;background:#fff;border:1px solid #f0a8a8;border-radius:6px;padding:7px 12px;margin-bottom:10px;text-align:center;"><p class="text-11px font-semibold leading-relaxed" style="color:#dc2626;margin:0;"><?= $e($payNote) ?></p></div><?php endif ?>
    <div style="display:flex;gap:16px;align-items:stretch;">
      <div class="bd" style="flex:1;border-radius:6px;padding:8px;display:flex;flex-direction:column;"><div style="flex:1;min-height:56px;"></div><div style="border-bottom:1px dotted var(--border);margin-bottom:4px;"></div><p class="text-11px text-center c-text"><?= $p('ผู้วางบิล', 'Biller') ?></p>
        <div style="display:flex;align-items:flex-end;gap:6px;margin-top:12px;"><span class="text-11px c-text"><?= $p('วันที่', 'Date') ?></span><span style="flex:1;border-bottom:1px dotted var(--border);height:1px;"></span></div></div>
      <div class="bd" style="flex:2;border-radius:6px;overflow:hidden;"><table class="border-collapse" style="width:100%;table-layout:fixed;">
        <tr><td colspan="2" class="bd-b" style="text-align:center;padding:6px 8px;"><span class="text-11px c-text"><?= $p('ข้าพเจ้าได้รับเอกสาร / บริการ ดังรายการข้างต้นครบถ้วนและอยู่ในสภาพเรียบร้อย', 'I have received the documents / services listed above in complete and good condition') ?></span></td></tr>
        <tr><td class="bd-r" style="padding:8px;vertical-align:bottom;height:84px;width:50%;"><div style="border-bottom:1px dotted var(--border);margin-bottom:4px;margin-top:32px;"></div><p class="text-11px text-center c-text"><?= $p('ผู้รับวางบิล', 'Bill Receiver') ?></p>
          <div style="display:flex;align-items:flex-end;gap:6px;margin-top:12px;"><span class="text-11px c-text"><?= $p('วันที่', 'Date') ?></span><span style="flex:1;border-bottom:1px dotted var(--border);height:1px;"></span></div></td>
          <td style="padding:8px;vertical-align:bottom;height:84px;width:50%;"><div style="display:flex;align-items:flex-end;gap:6px;margin-top:12px;"><span class="text-11px c-text"><?= $p('วันที่ชำระเงิน', 'Payment Date') ?></span><span style="flex:1;border-bottom:1px dotted var(--border);height:1px;"></span></div></td></tr>
      </table></div>
    </div>
  </div>
</div>
</div>
</body></html>
