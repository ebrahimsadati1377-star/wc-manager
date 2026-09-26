<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
require_once __DIR__ . '/../includes/OrderShipmentService.php';

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$asCsv = (string)($_GET['format'] ?? '') === 'csv';
$invalid = false;
foreach ([$from,$to] as $date) {
    if ($date === '') continue;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) $invalid = true;
}
if ($from !== '' && $to !== '' && $from > $to) $invalid = true;
if ($invalid) {
    http_response_code(400);
    exit('بازه تاریخ معتبر نیست.');
}

$params = ['per_page'=>100,'page'=>1,'status'=>'any','orderby'=>'date','order'=>'desc'];
if ($from !== '') $params['after'] = $from . 'T00:00:00';
if ($to !== '') $params['before'] = $to . 'T23:59:59';
$wc = new WooCommerceClient();
$rows=[]; $page=1; $pageCount=1; $error='';
$limit=5000;
do {
    $params['page'] = $page;
    $result=$wc->getOrders($params);
    if (!empty($result['error']) || !is_array($result['body'] ?? null)) {
        $error = 'خواندن سفارش‌های ووکامرس ناموفق بود؛ گزارش ناقص تولید نشد.';
        break;
    }
    $pageCount=max(1,(int)($result['headers']['total_pages'] ?? 1));
    if ($pageCount > 50) {
        $error = 'حجم داده زیاد است؛ بازه زمانی کوتاه‌تری انتخاب کنید (حداکثر ۵۰۰۰ سفارش).';
        break;
    }
    foreach ($result['body'] as $order) {
        if (!is_array($order)) continue;
        $tracking=OrderShipmentService::meta($order,'_baji_ship_tracking');
        if ($tracking==='') continue;
        $actualRaw=OrderShipmentService::meta($order,'_baji_ship_actual_cost_toman');
        $known=$actualRaw!=='' && preg_match('/^\d+$/D',$actualRaw);
        $actual=$known?(int)$actualRaw:null;
        $charged=(int)round((float)($order['shipping_total']??0));
        $rows[]=[
            'id'=>(int)($order['id']??0),
            'date'=>(string)($order['date_created']??''),
            'ship_date'=>OrderShipmentService::meta($order,'_baji_ship_sent_at'),
            'status'=>(string)($order['status']??''),
            'carrier'=>OrderShipmentService::carrierLabel(
                OrderShipmentService::meta($order,'_baji_ship_carrier'),
                OrderShipmentService::meta($order,'_baji_ship_other')
            ),
            'tracking'=>$tracking,
            'charged'=>$charged,
            'actual'=>$actual,
            'delta'=>$actual===null?null:($charged-$actual),
            'actor'=>OrderShipmentService::meta($order,'_baji_ship_cost_recorded_by'),
        ];
    }
    $page++;
} while ($page <= $pageCount && $page<=50);

if ($error !== '' && $asCsv) {
    http_response_code(503);
    header('Content-Type:text/plain; charset=utf-8');
    exit($error);
}
$knownRows=array_values(array_filter($rows,static fn($r)=>$r['actual']!==null));
$sumCharged=array_sum(array_column($knownRows,'charged'));
$sumActual=array_sum(array_column($knownRows,'actual'));
$sumDelta=$sumCharged-$sumActual;
$csvCell=static function($value):string {
    $value=(string)$value;
    // Prevent spreadsheet formula injection in user-provided text.
    return preg_match('/^[\s]*[=+\-@]/u',$value) ? "'".$value : $value;
};

if ($asCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="baji-shipping-ledger.csv"');
    header('Cache-Control: private, no-store');
    $out=fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['شماره سفارش','تاریخ سفارش','تاریخ ارسال','وضعیت','شرکت','رهگیری','کرایه دریافتی از مشتری (تومان)','هزینه واقعی حمل (تومان)','مازاد/کسری حمل (تومان)','ثبت‌کننده هزینه']);
    foreach ($rows as $r) {
        fputcsv($out,[
            $r['id'],$r['date'],$r['ship_date'],$csvCell($r['status']),
            $csvCell($r['carrier']),$csvCell($r['tracking']),$r['charged'],
            $r['actual']===null?'':$r['actual'],$r['delta']===null?'':$r['delta'],
            $csvCell($r['actor'])
        ]);
    }
    fclose($out);
    exit;
}
$pageTitle='گزارش مالی ارسال';
require __DIR__.'/partials/header.php';
?>
<style>
.ship-ledger{max-width:1280px;margin:auto}.ship-ledger h1{font-weight:950;font-size:1.4rem}.ship-ledger .panel{background:#fff;border:1px solid #e8edf1;border-radius:18px;padding:1rem;margin:1rem 0;box-shadow:0 6px 22px rgba(17,24,39,.035)}.ship-ledger .form{display:flex;align-items:end;flex-wrap:wrap;gap:.7rem}.ship-ledger label{display:grid;gap:.3rem;font-size:.76rem;font-weight:850}.ship-ledger input{min-height:41px;border:1px solid #d9e0e6;border-radius:9px;padding:.45rem .65rem;font:inherit}.ship-ledger .btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:41px;padding:.5rem .85rem;background:#172a40;color:white;border:0;border-radius:10px;text-decoration:none;font-size:.76rem;font-weight:850}.ship-ledger .btn.csv{background:#14784e}.ship-ledger .metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.7rem}.ship-ledger .metric{border:1px solid #e6e9ee;border-radius:13px;padding:.8rem}.ship-ledger .metric span{font-size:.74rem;color:#667085}.ship-ledger .metric strong{display:block;font-size:1rem;margin-top:.3rem}.ship-ledger .scroll{overflow-x:auto}.ship-ledger table{width:100%;min-width:930px;border-collapse:collapse;font-size:.73rem}.ship-ledger th,.ship-ledger td{text-align:right;border-bottom:1px solid #edf0f3;padding:.6rem}.ship-ledger th{color:#475467;background:#f7f9fb}.ship-ledger td a{color:#176a4a}.ship-ledger .pos{color:#168356;font-weight:900}.ship-ledger .neg{color:#c0392b;font-weight:900}.ship-ledger .muted{color:#89939f;font-size:.74rem}.ship-ledger .notice{padding:.8rem;background:#fff6ed;color:#95501a;border-radius:12px;font-size:.8rem}
</style>
<div class="ship-ledger">
  <a href="orders.php" class="btn" style="background:#677587;margin-bottom:.6rem"><i class="fas fa-arrow-right"></i> بازگشت به سفارش‌ها</a>
  <h1>گزارش مالی ارسال سفارش‌ها</h1>
  <p class="muted">مقایسه کرایه دریافتی از مشتری با هزینه واقعی پرداخت‌شده به شرکت حمل؛ این اختلاف فقط نتیجه مالی بخش حمل است، نه سود خالص کل فروشگاه. تاریخ فیلتر مربوط به تاریخ ثبت سفارش است. هزینه ثبت‌نشده، صفر فرض نمی‌شود.</p>
  <div class="panel">
    <form class="form" method="get">
      <label>از تاریخ سفارش (میلادی)<input type="date" name="from" value="<?= e($from) ?>"></label>
      <label>تا تاریخ سفارش (میلادی)<input type="date" name="to" value="<?= e($to) ?>"></label>
      <button type="submit" class="btn">نمایش گزارش</button>
      <a class="btn csv" href="shipping_finance.php?<?= e(http_build_query(['from'=>$from,'to'=>$to,'format'=>'csv'])) ?>"><i class="fas fa-file-csv"></i> خروجی CSV حسابداری</a>
    </form>
  </div>
  <?php if ($error !== ''): ?><div class="notice"><?= e($error) ?></div><?php else: ?>
  <div class="panel metrics">
    <div class="metric"><span>سفارش‌های ارسال‌شده</span><strong><?= number_format(count($rows)) ?></strong></div>
    <div class="metric"><span>هزینه ثبت‌شده</span><strong><?= number_format(count($knownRows)) ?> مورد</strong></div>
    <div class="metric"><span>دریافتی حمل سفارش‌های دارای هزینه</span><strong><?= e(formatPrice($sumCharged)) ?></strong></div>
    <div class="metric"><span>هزینه واقعی حمل</span><strong><?= e(formatPrice($sumActual)) ?></strong></div>
    <div class="metric"><span>اختلاف کرایه (دریافتی − واقعی)</span><strong class="<?= $sumDelta>=0?'pos':'neg' ?>"><?= e(formatPrice($sumDelta)) ?></strong></div>
  </div>
  <div class="panel scroll"><table>
    <thead><tr><th>سفارش</th><th>زمان ارسال</th><th>شرکت</th><th>رهگیری</th><th>کرایه مشتری</th><th>هزینه واقعی</th><th>اختلاف</th><th>ثبت‌کننده</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="orders.php?view=<?= $r['id'] ?>">#<?= e((string)$r['id']) ?></a></td>
        <td><?= e($r['ship_date'] ? substr($r['ship_date'],0,16) : '—') ?></td>
        <td><?= e($r['carrier']) ?></td><td dir="ltr"><?= e($r['tracking']) ?></td>
        <td><?= e(formatPrice($r['charged'])) ?></td>
        <td><?= $r['actual']===null ? '<span class="muted">ثبت نشده</span>' : e(formatPrice($r['actual'])) ?></td>
        <td><?php if($r['delta']===null): ?>—<?php else: ?><span class="<?= $r['delta']>=0?'pos':'neg' ?>"><?= e(formatPrice($r['delta'])) ?></span><?php endif; ?></td>
        <td><?= e($r['actor'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted">سفارش ارسال‌شده‌ای در این بازه وجود ندارد.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__.'/partials/footer.php';