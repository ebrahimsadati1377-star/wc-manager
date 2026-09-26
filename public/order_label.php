<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
require_once __DIR__ . '/../includes/OrderShipmentService.php';

$orderId = max(0, (int)($_GET['view'] ?? 0));
$res = $orderId ? (new WooCommerceClient())->getOrder($orderId) : ['error'=>'invalid'];
$order = empty($res['error']) && (int)($res['body']['id'] ?? 0) === $orderId ? (array)$res['body'] : null;
if (!$order) {
    http_response_code(404);
    exit('سفارش پیدا نشد.');
}
$billing = (array)($order['billing'] ?? []);
$shipping = (array)($order['shipping'] ?? []);
$recipient = array_filter([
    trim((string)($shipping['first_name'] ?? '')),
    trim((string)($shipping['last_name'] ?? '')),
]);
if (!$recipient) $recipient = array_filter([trim((string)($billing['first_name'] ?? '')),trim((string)($billing['last_name'] ?? ''))]);
$recipientName = implode(' ', $recipient);
$destination = array_filter([
    trim((string)($shipping['state'] ?? $billing['state'] ?? '')),
    trim((string)($shipping['city'] ?? $billing['city'] ?? '')),
    trim((string)($shipping['address_1'] ?? $billing['address_1'] ?? '')),
    trim((string)($shipping['address_2'] ?? $billing['address_2'] ?? '')),
]);
$postcode = trim((string)($shipping['postcode'] ?? '')) ?: trim((string)($billing['postcode'] ?? ''));
$phone = trim((string)($billing['phone'] ?? ''));
$carrier = OrderShipmentService::meta($order, '_baji_ship_carrier');
$other = OrderShipmentService::meta($order, '_baji_ship_other');
$tracking = OrderShipmentService::meta($order, '_baji_ship_tracking');

/* Independent Code 39 barcode: internal order identifier, not carrier's barcode. */
function bajiOrderBarcode(string $data): string
{
    $patterns = [
        '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn',
        '4'=>'nnnwwnnnw','5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw',
        '8'=>'wnnwnnwnn','9'=>'nnwwnnwnn','A'=>'wnnnnwnnw','B'=>'nnwnnwnnw',
        'I'=>'nnwnnwwnn','J'=>'nnnnwwwnn','*'=>'nwnnwnwnn'
    ];
    $barcode = '*'.$data.'*';
    $bars = [];
    $x = 9.0;
    $n = 2.0; $w = 4.8;
    foreach (str_split($barcode) as $letter) {
        $pattern = $patterns[$letter] ?? null;
        if (!$pattern) continue;
        foreach (str_split($pattern) as $i => $p) {
            $width = $p === 'w' ? $w : $n;
            if ($i % 2 === 0) $bars[] = '<rect x="'.number_format($x, 2, '.', '').'" y="4" width="'.number_format($width, 2, '.', '').'" height="50"/>';
            $x += $width;
        }
        $x += $n;
    }
    $viewWidth = ceil($x + 9);
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$viewWidth.' 58" role="img" aria-label="بارکد داخلی شماره سفارش" preserveAspectRatio="xMidYMid meet">'.implode('', $bars).'</svg>';
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>لیبل ارسال سفارش #<?= $orderId ?> — BAJI</title>
<style>
*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,Arial,sans-serif;background:#eef2f6;color:#101828;margin:0;padding:18px;line-height:1.8}
.actions{text-align:center;margin:0 auto 14px}.actions button,.actions a{border:0;border-radius:10px;cursor:pointer;background:#163e2b;color:#fff;padding:9px 17px;font:800 13px inherit;text-decoration:none;display:inline-block;margin:0 4px}.actions a{background:#556273}
.label{background:white;border:1px solid #d6dbe1;max-width:105mm;min-height:148mm;padding:6mm;margin:auto;border-radius:10px;box-shadow:0 9px 20px rgba(0,0,0,.07)}
.header{display:flex;align-items:flex-start;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:3mm;gap:8px}.brand{font:bold 24px Georgia,serif;letter-spacing:.13em}.subtitle{font-size:10px}.number{font-weight:900;font-size:17px;text-align:left;direction:ltr}
.block{border-bottom:1px dashed #aeb5bf;padding:3mm 0;font-size:12px}.caption{font-weight:850;font-size:11px;color:#54606e;display:block}.detail{font-size:13px;font-weight:850;overflow-wrap:anywhere}.address{font-size:12px;line-height:2;overflow-wrap:anywhere}.row{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.tracking{font-size:14px;font-weight:900;direction:ltr;text-align:left;overflow-wrap:anywhere}
.barcode{text-align:center;padding:3mm 0 1mm}.barcode svg{display:block;height:48px;max-width:100%;margin:auto;fill:#111}.barcode span{font:800 13px Arial;letter-spacing:.1em;direction:ltr}
.small{font-size:10px;color:#475467;line-height:1.8}.item{font-size:10px;margin:2px 0;overflow-wrap:anywhere}.no-break{break-inside:avoid}
@page{size:A6 portrait;margin:0}
@media print{html,body{background:#fff;margin:0;padding:0}.actions{display:none!important}.label{width:105mm;min-height:148mm;max-width:none;border:0;border-radius:0;box-shadow:none;margin:0;padding:6mm}}
</style></head><body>
<div class="actions">
  <button type="button" onclick="window.print()">چاپ لیبل / ذخیره PDF</button>
  <a href="orders.php?view=<?= $orderId ?>">بازگشت به سفارش</a>
</div>
<article class="label">
  <div class="header"><div><div class="brand">BAJI</div><div class="subtitle">باجی — فروشگاه پوشاک زنانه</div></div><div class="number">#<?= $orderId ?><div class="small">شناسه داخلی سفارش</div></div></div>
  <div class="block"><span class="caption">گیرنده</span><div class="detail"><?= e($recipientName ?: 'نام گیرنده ثبت نشده') ?></div></div>
  <div class="block"><span class="caption">نشانی تحویل</span><div class="address"><?= e($destination ? implode('، ', $destination) : 'نشانی ثبت نشده') ?></div></div>
  <div class="block row no-break"><div><span class="caption">کد پستی</span><div class="detail" dir="ltr"><?= e($postcode ?: 'ثبت نشده') ?></div></div><div><span class="caption">شماره تماس</span><div class="detail" dir="ltr"><?= e($phone ?: 'ثبت نشده') ?></div></div></div>
  <div class="block row no-break"><div><span class="caption">شرکت حمل‌ونقل</span><div class="detail"><?= e($carrier !== '' ? OrderShipmentService::carrierLabel($carrier,$other) : 'هنوز انتخاب نشده') ?></div></div><div><span class="caption">کد رهگیری شرکت حمل</span><div class="tracking"><?= e($tracking ?: 'هنوز ثبت نشده') ?></div></div></div>
  <div class="barcode no-break"><?= bajiOrderBarcode('BAJI'.$orderId) ?><span>BAJI<?= $orderId ?></span><div class="small">بارکد شناسه داخلی BAJI است؛ بارکد شرکت پستی نیست.</div></div>
  <div class="block no-break"><span class="caption">چک‌لیست بسته‌بندی (داخلی)</span>
  <?php foreach ((array)($order['line_items'] ?? []) as $item): ?>
  <div class="item">□ <?= e((string)($item['name'] ?? 'کالا')) ?> × <?= (int)($item['quantity'] ?? 1) ?></div>
  <?php endforeach; ?>
  </div>
  <div class="small">bajistyle.ir · باجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی</div>
</article>
</body></html>