
<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$wc = new WooCommerceClient();

function ordersFaDigits(string $value): string
{
    return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

function ordersStatusMeta(string $status): array
{
    $map = [
        'pending'        => ['در انتظار پرداخت', 'pending', 'fa-clock'],
        'processing'     => ['در حال پردازش', 'processing', 'fa-box-open'],
        'on-hold'        => ['در انتظار بررسی', 'onhold', 'fa-pause'],
        'completed'      => ['تکمیل‌شده', 'completed', 'fa-circle-check'],
        'cancelled'      => ['لغوشده', 'cancelled', 'fa-ban'],
        'refunded'       => ['بازپرداخت‌شده', 'refunded', 'fa-rotate-left'],
        'failed'         => ['ناموفق', 'failed', 'fa-triangle-exclamation'],
        'checkout-draft' => ['پیش‌نویس تسویه', 'draft', 'fa-file'],
    ];
    return $map[$status] ?? [$status !== '' ? $status : 'نامشخص', 'unknown', 'fa-circle'];
}

function ordersFormatDate(?string $value, bool $withTime = true): string
{
    if (!$value) return '—';
    try {
        $dt = new DateTimeImmutable($value);
        $dt = $dt->setTimezone(new DateTimeZone('Asia/Tehran'));
        if (class_exists('IntlDateFormatter')) {
            $pattern = $withTime ? 'yyyy/MM/dd - HH:mm' : 'yyyy/MM/dd';
            $fmt = new IntlDateFormatter(
                'fa_IR@calendar=persian',
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'Asia/Tehran',
                IntlDateFormatter::TRADITIONAL,
                $pattern
            );
            $formatted = $fmt->format($dt);
            if ($formatted !== false) return (string)$formatted;
        }
        return ordersFaDigits($dt->format($withTime ? 'Y/m/d - H:i' : 'Y/m/d'));
    } catch (Throwable $e) {
        return '—';
    }
}

function ordersCustomerName(array $order): string
{
    $billing = (array)($order['billing'] ?? []);
    $name = trim((string)($billing['first_name'] ?? '') . ' ' . (string)($billing['last_name'] ?? ''));
    return $name !== '' ? $name : 'مشتری بدون نام';
}

function ordersAddress(array $address): string
{
    $parts = array_filter([
        trim((string)($address['state'] ?? '')),
        trim((string)($address['city'] ?? '')),
        trim((string)($address['address_1'] ?? '')),
        trim((string)($address['address_2'] ?? '')),
    ], static fn($v) => $v !== '');
    return $parts ? implode('، ', $parts) : 'ثبت نشده';
}

function ordersQueryUrl(array $changes = []): string
{
    $query = $_GET;
    unset($query['view']);
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return 'orders.php' . ($query ? '?' . http_build_query($query) : '');
}

$statusOptions = [
    'all' => 'همه وضعیت‌ها',
    'processing' => 'در حال پردازش',
    'pending' => 'در انتظار پرداخت',
    'on-hold' => 'در انتظار بررسی',
    'completed' => 'تکمیل‌شده',
    'cancelled' => 'لغوشده',
    'refunded' => 'بازپرداخت‌شده',
    'failed' => 'ناموفق',
];

$viewId = max(0, (int)($_GET['view'] ?? 0));
$pageTitle = $viewId > 0 ? 'جزئیات سفارش' : 'سفارش‌ها';
require __DIR__ . '/partials/header.php';

if ($viewId > 0):
    $detailRes = $wc->getOrder($viewId);
    $order = !$detailRes['error'] && is_array($detailRes['body']) ? $detailRes['body'] : null;
?>
<style>
.orders-shell{max-width:1450px;margin:0 auto}.orders-back{display:inline-flex;align-items:center;gap:.45rem;color:#5b6472;text-decoration:none;font-size:.82rem;font-weight:800;margin-bottom:.9rem}.orders-back:hover{color:#155dcc}
.order-detail-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:1.3rem 1.4rem;border-radius:22px;background:linear-gradient(135deg,#111827,#17243b 60%,#164e63);color:#fff;box-shadow:0 16px 42px rgba(15,23,42,.14);margin-bottom:1rem}
.order-detail-kicker{color:#93c5fd;font-size:.72rem;font-weight:800}.order-detail-title{font-size:clamp(1.35rem,4vw,2rem);font-weight:900;margin:.2rem 0 0}.order-detail-date{color:#cbd5e1;font-size:.78rem;margin-top:.35rem}
.order-status{display:inline-flex;align-items:center;gap:.4rem;border-radius:999px;padding:.48rem .72rem;font-size:.72rem;font-weight:900;white-space:nowrap}.order-status.processing{background:#dbeafe;color:#1d4ed8}.order-status.pending{background:#fff7ed;color:#c2410c}.order-status.onhold{background:#fef3c7;color:#a16207}.order-status.completed{background:#dcfce7;color:#15803d}.order-status.cancelled,.order-status.failed{background:#fee2e2;color:#b91c1c}.order-status.refunded{background:#ede9fe;color:#6d28d9}.order-status.draft,.order-status.unknown{background:#f3f4f6;color:#4b5563}
.order-detail-hero .order-status{box-shadow:inset 0 0 0 1px rgba(255,255,255,.12)}
.order-detail-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(310px,.65fr);gap:1rem;align-items:start}.order-card{background:#fff;border:1px solid #e8ebef;border-radius:19px;padding:1.1rem;box-shadow:0 7px 24px rgba(15,23,42,.04);margin-bottom:1rem}.order-card-title{display:flex;align-items:center;gap:.55rem;font-size:.9rem;font-weight:900;margin:0 0 1rem}.order-card-title i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#eff6ff;color:#2563eb}
.order-items{display:grid;gap:.7rem}.order-item{display:grid;grid-template-columns:58px minmax(0,1fr) auto;gap:.8rem;align-items:center;padding:.75rem;border:1px solid #edf0f3;border-radius:15px;background:#fcfcfd}.order-item-image{width:58px;height:72px;border-radius:11px;background:#f3f4f6;overflow:hidden;display:grid;place-items:center;color:#9ca3af}.order-item-image img{width:100%;height:100%;object-fit:cover}.order-item-name{font-size:.8rem;font-weight:900;color:#222b38;line-height:1.8}.order-item-meta{color:#7b8492;font-size:.69rem;line-height:1.8;margin-top:.15rem}.order-item-total{text-align:left;white-space:nowrap;font-size:.77rem;font-weight:900;color:#111827}
.order-info-list{display:grid;gap:.7rem}.order-info-row{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding-bottom:.7rem;border-bottom:1px dashed #e6e9ed}.order-info-row:last-child{border-bottom:0;padding-bottom:0}.order-info-label{color:#7a8492;font-size:.7rem}.order-info-value{font-size:.76rem;font-weight:800;color:#263140;text-align:left;line-height:1.8;max-width:62%}.order-info-value a{color:#155dcc;text-decoration:none}
.order-total-box{border-radius:16px;background:#f8fafc;padding:.9rem}.order-total-row{display:flex;justify-content:space-between;gap:1rem;font-size:.76rem;color:#657080;margin:.48rem 0}.order-total-row strong{color:#253142}.order-total-row.grand{border-top:1px solid #e4e8ed;padding-top:.7rem;margin-top:.7rem;font-size:.9rem;font-weight:900}.order-total-row.grand strong{color:#111827;font-size:1.05rem}
.order-note{padding:.85rem;border-radius:14px;background:#fffbeb;color:#854d0e;font-size:.76rem;line-height:1.9}.order-actions{display:flex;gap:.5rem;flex-wrap:wrap}.order-action{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:42px;padding:.55rem .75rem;border-radius:11px;text-decoration:none;font-size:.75rem;font-weight:850;border:1px solid #dfe4ea;background:#fff;color:#344054}.order-action.primary{background:#111827;border-color:#111827;color:#fff}
@media(max-width:900px){.order-detail-grid{grid-template-columns:1fr}.order-item{grid-template-columns:48px minmax(0,1fr);}.order-item-image{width:48px;height:62px}.order-item-total{grid-column:2;text-align:right}.order-info-value{max-width:58%}}@media(max-width:520px){.order-detail-hero{padding:1.05rem;border-radius:18px}.order-card{padding:.85rem;border-radius:16px}.order-info-row{display:grid;gap:.25rem}.order-info-value{max-width:none;text-align:right}}
</style>
<div class="orders-shell">
  <a href="orders.php" class="orders-back"><i class="fas fa-arrow-right"></i> بازگشت به سفارش‌ها</a>
  <?php if (!$order): ?>
    <div class="alert alert-danger">سفارش پیدا نشد یا ارتباط با ووکامرس خطا دارد: <?= e((string)($detailRes['error'] ?? 'خطای نامشخص')) ?></div>
  <?php else:
    [$statusLabel,$statusClass,$statusIcon] = ordersStatusMeta((string)($order['status'] ?? ''));
    $billing = (array)($order['billing'] ?? []);
    $shipping = (array)($order['shipping'] ?? []);
    $phone = trim((string)($billing['phone'] ?? ''));
    $email = trim((string)($billing['email'] ?? ''));
    $billingPostcode = trim((string)($billing['postcode'] ?? ''));
    $shippingPostcode = trim((string)($shipping['postcode'] ?? ''));
    $postcode = $shippingPostcode !== '' ? $shippingPostcode : $billingPostcode;
    $shippingLines = array_map(static fn($line) => (string)($line['method_title'] ?? ''), (array)($order['shipping_lines'] ?? []));
    $shippingLines = array_filter($shippingLines);
    $itemSubtotal = 0.0;
    foreach ((array)($order['line_items'] ?? []) as $line) $itemSubtotal += (float)($line['subtotal'] ?? 0);
  ?>
    <section class="order-detail-hero">
      <div>
        <div class="order-detail-kicker">ORDER DETAILS</div>
        <h1 class="order-detail-title">سفارش #<?= e(ordersFaDigits((string)$order['id'])) ?></h1>
        <div class="order-detail-date"><?= e(ordersFormatDate((string)($order['date_created'] ?? ''))) ?></div>
      </div>
      <span class="order-status <?= e($statusClass) ?>"><i class="fas <?= e($statusIcon) ?>"></i><?= e($statusLabel) ?></span>
    </section>

    <div class="order-detail-grid">
      <main>
        <section class="order-card">
          <h2 class="order-card-title"><i class="fas fa-bag-shopping"></i> اقلام سفارش</h2>
          <div class="order-items">
            <?php foreach ((array)($order['line_items'] ?? []) as $item):
              $image = trim((string)($item['image']['src'] ?? ''));
              $visibleMeta = [];
              foreach ((array)($item['meta_data'] ?? []) as $meta) {
                  $key = (string)($meta['display_key'] ?? $meta['key'] ?? '');
                  if ($key === '' || str_starts_with($key, '_')) continue;
                  $value = $meta['display_value'] ?? $meta['value'] ?? '';
                  if (is_scalar($value) && trim((string)$value) !== '') $visibleMeta[] = $key . ': ' . (string)$value;
              }
            ?>
              <article class="order-item">
                <div class="order-item-image">
                  <?php if ($image !== ''): ?><img src="<?= e($image) ?>" alt="" loading="lazy"><?php else: ?><i class="fas fa-shirt"></i><?php endif; ?>
                </div>
                <div>
                  <div class="order-item-name"><?= e((string)($item['name'] ?? 'محصول')) ?></div>
                  <div class="order-item-meta">
                    تعداد: <?= e(ordersFaDigits((string)($item['quantity'] ?? 1))) ?>
                    <?php if (!empty($item['sku'])): ?> · کد: <?= e((string)$item['sku']) ?><?php endif; ?>
                    <?php if ($visibleMeta): ?><br><?= e(implode(' · ', $visibleMeta)) ?><?php endif; ?>
                  </div>
                </div>
                <div class="order-item-total"><?= e(formatPrice($item['total'] ?? 0)) ?></div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <?php if (!empty($order['customer_note'])): ?>
          <section class="order-card">
            <h2 class="order-card-title"><i class="fas fa-message"></i> یادداشت مشتری</h2>
            <div class="order-note"><?= nl2br(e((string)$order['customer_note'])) ?></div>
          </section>
        <?php endif; ?>
      </main>

      <aside>
        <section class="order-card">
          <h2 class="order-card-title"><i class="fas fa-user"></i> مشتری</h2>
          <div class="order-info-list">
            <div class="order-info-row"><span class="order-info-label">نام</span><span class="order-info-value"><?= e(ordersCustomerName($order)) ?></span></div>
            <div class="order-info-row"><span class="order-info-label">موبایل</span><span class="order-info-value"><?php if ($phone !== ''): ?><a href="tel:<?= e($phone) ?>"><?= e(ordersFaDigits($phone)) ?></a><?php else: ?>ثبت نشده<?php endif; ?></span></div>
            <div class="order-info-row"><span class="order-info-label">ایمیل</span><span class="order-info-value"><?= $email !== '' ? e($email) : 'ثبت نشده' ?></span></div>
            <div class="order-info-row"><span class="order-info-label">کد پستی</span><span class="order-info-value" dir="ltr"><?= $postcode !== '' ? e(ordersFaDigits($postcode)) : 'ثبت نشده' ?></span></div>
            <div class="order-info-row"><span class="order-info-label">آدرس صورتحساب</span><span class="order-info-value"><?= e(ordersAddress($billing)) ?></span></div>
            <div class="order-info-row"><span class="order-info-label">آدرس ارسال</span><span class="order-info-value"><?= e(ordersAddress($shipping ?: $billing)) ?></span></div>
          </div>
          <?php if ($phone !== ''): ?>
          <div class="order-actions mt-3">
            <a class="order-action primary" href="tel:<?= e($phone) ?>"><i class="fas fa-phone"></i> تماس با مشتری</a>
            <button class="order-action" type="button" onclick="navigator.clipboard?.writeText('<?= e($phone) ?>')"><i class="fas fa-copy"></i> کپی شماره</button>
          </div>
          <?php endif; ?>
        </section>

        <section class="order-card">
          <h2 class="order-card-title"><i class="fas fa-credit-card"></i> پرداخت و ارسال</h2>
          <div class="order-info-list">
            <div class="order-info-row"><span class="order-info-label">روش پرداخت</span><span class="order-info-value"><?= e((string)($order['payment_method_title'] ?? 'ثبت نشده')) ?></span></div>
            <div class="order-info-row"><span class="order-info-label">شناسه تراکنش</span><span class="order-info-value"><?= e((string)($order['transaction_id'] ?: '—')) ?></span></div>
            <div class="order-info-row"><span class="order-info-label">زمان پرداخت</span><span class="order-info-value"><?= e(ordersFormatDate($order['date_paid'] ?? null)) ?></span></div>
            <div class="order-info-row"><span class="order-info-label">روش ارسال</span><span class="order-info-value"><?= $shippingLines ? e(implode('، ', $shippingLines)) : 'ثبت نشده' ?></span></div>
          </div>
        </section>

        <section class="order-card">
          <h2 class="order-card-title"><i class="fas fa-receipt"></i> جمع سفارش</h2>
          <div class="order-total-box">
            <div class="order-total-row"><span>جمع کالاها</span><strong><?= e(formatPrice($itemSubtotal)) ?></strong></div>
            <div class="order-total-row"><span>تخفیف</span><strong><?= e(formatPrice($order['discount_total'] ?? 0)) ?></strong></div>
            <div class="order-total-row"><span>هزینه ارسال</span><strong><?= e(formatPrice($order['shipping_total'] ?? 0)) ?></strong></div>
            <div class="order-total-row grand"><span>مبلغ نهایی</span><strong><?= e(formatPrice($order['total'] ?? 0)) ?></strong></div>
          </div>
        </section>
      </aside>
    </div>
  <?php endif; ?>
</div>
<?php
require __DIR__ . '/partials/footer.php';
exit;
endif;

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$status = (string)($_GET['status'] ?? 'all');
if (!array_key_exists($status, $statusOptions)) $status = 'all';
$search = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) $search = mb_substr($search, 0, 100, 'UTF-8');
else $search = substr($search, 0, 100);
$period = (string)($_GET['period'] ?? 'all');
if (!in_array($period, ['all','today','7d','30d'], true)) $period = 'all';

$params = [
    'per_page' => $perPage,
    'page' => $page,
    'orderby' => 'date',
    'order' => 'desc',
    'status' => $status === 'all' ? 'any' : $status,
];
if ($search !== '') $params['search'] = $search;

$tz = new DateTimeZone('Asia/Tehran');
$today = new DateTimeImmutable('today', $tz);
if ($period === 'today') $params['after'] = $today->format(DATE_ATOM);
if ($period === '7d') $params['after'] = $today->modify('-6 days')->format(DATE_ATOM);
if ($period === '30d') $params['after'] = $today->modify('-29 days')->format(DATE_ATOM);

$listRes = $wc->getOrders($params);
$orders = !$listRes['error'] && is_array($listRes['body']) ? $listRes['body'] : [];
$totalOrders = (int)($listRes['headers']['total'] ?? 0);
$totalPages = max(1, (int)($listRes['headers']['total_pages'] ?? 1));

$countOrders = static function(WooCommerceClient $client, array $query): int {
    $res = $client->getOrders(array_merge(['per_page' => 1], $query));
    return $res['error'] ? 0 : (int)($res['headers']['total'] ?? 0);
};
$allCount = $countOrders($wc, ['status' => 'any']);
$todayCount = $countOrders($wc, ['status' => 'any', 'after' => $today->format(DATE_ATOM)]);
$processingCount = $countOrders($wc, ['status' => 'processing']);
$completedCount = $countOrders($wc, ['status' => 'completed']);
?>
<style>
.orders-shell{max-width:1450px;margin:0 auto}.orders-hero{position:relative;overflow:hidden;border-radius:24px;padding:1.35rem 1.45rem;background:linear-gradient(135deg,#111827 0%,#18243a 58%,#123c69 100%);color:#fff;box-shadow:0 18px 48px rgba(15,23,42,.15);margin-bottom:1rem}.orders-hero:after{content:"";position:absolute;width:230px;height:230px;border-radius:50%;left:-80px;top:-120px;background:rgba(59,130,246,.18)}
.orders-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.orders-kicker{color:#93c5fd;font-size:.72rem;font-weight:850}.orders-title{font-size:clamp(1.45rem,4vw,2.1rem);font-weight:950;letter-spacing:-.035em;margin:.18rem 0 0}.orders-subtitle{color:#cbd5e1;font-size:.82rem;margin:.4rem 0 0;line-height:1.9}.orders-refresh{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:44px;padding:.6rem .85rem;border-radius:12px;border:1px solid rgba(255,255,255,.16);color:#fff;text-decoration:none;background:rgba(255,255,255,.07);font-size:.76rem;font-weight:850}.orders-refresh:hover{color:#fff;background:rgba(255,255,255,.12)}
.orders-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}.orders-metric{background:#fff;border:1px solid #e8ebef;border-radius:18px;padding:1rem;box-shadow:0 6px 22px rgba(15,23,42,.04)}.orders-metric-head{display:flex;align-items:center;justify-content:space-between;gap:.7rem}.orders-metric-icon{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:#eff6ff;color:#2563eb}.orders-metric-icon.amber{background:#fff7ed;color:#ea580c}.orders-metric-icon.green{background:#ecfdf3;color:#15803d}.orders-metric-icon.violet{background:#f5f3ff;color:#7c3aed}.orders-metric-value{font-size:1.6rem;font-weight:950;margin-top:.7rem;line-height:1}.orders-metric-label{font-size:.72rem;color:#737e8d;margin-top:.35rem}
.orders-filter{background:#fff;border:1px solid #e8ebef;border-radius:18px;padding:.85rem;box-shadow:0 6px 22px rgba(15,23,42,.035);margin-bottom:1rem}.orders-filter-form{display:grid;grid-template-columns:minmax(240px,1.4fr) repeat(2,minmax(150px,.65fr)) auto;gap:.65rem}.orders-field{position:relative}.orders-field i{position:absolute;right:.82rem;top:50%;transform:translateY(-50%);color:#98a2b3;font-size:.8rem}.orders-input,.orders-select{width:100%;min-height:45px;border:1px solid #dfe4ea;border-radius:12px;background:#fbfcfd;color:#344054;font-family:inherit;font-size:.76rem;outline:0}.orders-input{padding:.55rem 2.35rem .55rem .75rem}.orders-select{padding:.55rem .75rem}.orders-input:focus,.orders-select:focus{border-color:#93b7f2;box-shadow:0 0 0 3px rgba(59,130,246,.08)}.orders-filter-btn{min-height:45px;border:0;border-radius:12px;background:#111827;color:#fff;padding:.55rem 1rem;font-family:inherit;font-size:.76rem;font-weight:900}.orders-filter-clear{display:inline-flex;align-items:center;justify-content:center;min-height:45px;padding:.55rem .7rem;border-radius:12px;border:1px solid #e1e5ea;color:#647184;text-decoration:none;font-size:.72rem;font-weight:800}
.orders-panel{background:#fff;border:1px solid #e8ebef;border-radius:20px;overflow:hidden;box-shadow:0 7px 24px rgba(15,23,42,.04)}.orders-panel-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.05rem;border-bottom:1px solid #edf0f3}.orders-panel-title{font-size:.88rem;font-weight:950;color:#263140}.orders-panel-meta{font-size:.7rem;color:#8a94a3}.orders-table{margin:0}.orders-table th{background:#f8fafc;color:#697586;font-size:.68rem;font-weight:900;border-bottom:1px solid #e8ecf0;padding:.75rem .8rem;white-space:nowrap}.orders-table td{padding:.78rem .8rem;vertical-align:middle;border-color:#f0f2f5;font-size:.74rem;color:#344054}.order-number{font-weight:950;color:#155dcc;text-decoration:none}.order-customer{font-weight:850;color:#222b38}.order-sub{color:#8a94a3;font-size:.65rem;margin-top:.16rem}.order-amount{font-weight:950;color:#111827;white-space:nowrap}.order-view{width:35px;height:35px;border-radius:10px;display:grid;place-items:center;background:#f3f6fa;color:#475467;text-decoration:none}.order-view:hover{background:#eaf2ff;color:#155dcc}
.order-status{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.37rem .58rem;font-size:.64rem;font-weight:900;white-space:nowrap}.order-status.processing{background:#dbeafe;color:#1d4ed8}.order-status.pending{background:#fff7ed;color:#c2410c}.order-status.onhold{background:#fef3c7;color:#a16207}.order-status.completed{background:#dcfce7;color:#15803d}.order-status.cancelled,.order-status.failed{background:#fee2e2;color:#b91c1c}.order-status.refunded{background:#ede9fe;color:#6d28d9}.order-status.draft,.order-status.unknown{background:#f3f4f6;color:#4b5563}
.orders-mobile-list{display:none;padding:.7rem}.order-mobile-card{border:1px solid #e9edf1;border-radius:16px;padding:.85rem;margin-bottom:.65rem;background:#fff}.order-mobile-top,.order-mobile-row{display:flex;align-items:center;justify-content:space-between;gap:.8rem}.order-mobile-top{margin-bottom:.75rem}.order-mobile-id{font-weight:950;color:#155dcc;text-decoration:none}.order-mobile-customer{font-size:.82rem;font-weight:900;color:#253142}.order-mobile-row{padding:.45rem 0;border-top:1px dashed #edf0f3;font-size:.7rem}.order-mobile-row span:first-child{color:#8a94a3}.order-mobile-row strong{color:#344054;text-align:left}.order-mobile-actions{display:grid;grid-template-columns:1fr auto;gap:.5rem;margin-top:.65rem}.order-mobile-details{display:flex;align-items:center;justify-content:center;gap:.4rem;min-height:40px;border-radius:11px;background:#111827;color:#fff;text-decoration:none;font-size:.72rem;font-weight:900}.order-mobile-call{width:42px;height:40px;border-radius:11px;border:1px solid #dfe4ea;display:grid;place-items:center;color:#2563eb;text-decoration:none}
.orders-empty{padding:3rem 1rem;text-align:center;color:#7a8492}.orders-empty i{display:grid;place-items:center;width:54px;height:54px;margin:0 auto .8rem;border-radius:16px;background:#f3f6fa;color:#98a2b3;font-size:1.25rem}.orders-empty strong{display:block;color:#344054;font-size:.9rem;margin-bottom:.3rem}
.orders-pagination{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:.85rem 1rem;border-top:1px solid #edf0f3}.orders-pages{display:flex;gap:.35rem}.orders-page{min-width:36px;height:36px;border:1px solid #e0e5eb;border-radius:10px;display:grid;place-items:center;color:#526071;text-decoration:none;font-size:.7rem;font-weight:850}.orders-page.active{background:#111827;border-color:#111827;color:#fff}.orders-page.disabled{opacity:.4;pointer-events:none}.orders-page-info{font-size:.68rem;color:#8a94a3}
@media(max-width:991px){.orders-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.orders-filter-form{grid-template-columns:1fr 1fr}.orders-filter-form .orders-field:first-child{grid-column:1/-1}.orders-filter-btn,.orders-filter-clear{width:100%}}@media(max-width:767px){.orders-hero{padding:1.1rem;border-radius:19px}.orders-metrics{gap:.55rem}.orders-metric{padding:.8rem;border-radius:15px}.orders-metric-value{font-size:1.35rem}.orders-filter{padding:.7rem;border-radius:15px}.orders-filter-form{grid-template-columns:1fr}.orders-filter-form .orders-field:first-child{grid-column:auto}.orders-desktop-table{display:none}.orders-mobile-list{display:block}.orders-panel-head{padding:.85rem}.orders-pagination{flex-wrap:wrap}.orders-page-info{width:100%;text-align:center;order:2}.orders-pages{margin:auto}}@media(max-width:420px){.orders-metrics{grid-template-columns:1fr 1fr}.orders-metric-label{font-size:.66rem}}
</style>

<div class="orders-shell">
  <section class="orders-hero">
    <div class="orders-hero-inner">
      <div>
        <div class="orders-kicker">BAJI ORDERS</div>
        <h1 class="orders-title">مدیریت سفارش‌ها</h1>
        <p class="orders-subtitle">همه سفارش‌های سایت، وضعیت پرداخت، اطلاعات مشتری و جزئیات خرید در یک صفحه.</p>
      </div>
      <a class="orders-refresh" href="<?= e(ordersQueryUrl(['page'=>1])) ?>"><i class="fas fa-rotate"></i> تازه‌سازی سفارش‌ها</a>
    </div>
  </section>

  <section class="orders-metrics" aria-label="آمار سفارش‌ها">
    <div class="orders-metric"><div class="orders-metric-head"><span class="orders-metric-label">کل سفارش‌ها</span><span class="orders-metric-icon"><i class="fas fa-receipt"></i></span></div><div class="orders-metric-value"><?= e(ordersFaDigits((string)$allCount)) ?></div><div class="orders-metric-label">ثبت‌شده در ووکامرس</div></div>
    <div class="orders-metric"><div class="orders-metric-head"><span class="orders-metric-label">سفارش امروز</span><span class="orders-metric-icon violet"><i class="fas fa-calendar-day"></i></span></div><div class="orders-metric-value"><?= e(ordersFaDigits((string)$todayCount)) ?></div><div class="orders-metric-label">از ابتدای امروز</div></div>
    <div class="orders-metric"><div class="orders-metric-head"><span class="orders-metric-label">در حال پردازش</span><span class="orders-metric-icon amber"><i class="fas fa-box-open"></i></span></div><div class="orders-metric-value"><?= e(ordersFaDigits((string)$processingCount)) ?></div><div class="orders-metric-label">نیازمند پیگیری</div></div>
    <div class="orders-metric"><div class="orders-metric-head"><span class="orders-metric-label">تکمیل‌شده</span><span class="orders-metric-icon green"><i class="fas fa-circle-check"></i></span></div><div class="orders-metric-value"><?= e(ordersFaDigits((string)$completedCount)) ?></div><div class="orders-metric-label">سفارش‌های نهایی‌شده</div></div>
  </section>

  <section class="orders-filter">
    <form class="orders-filter-form" method="get" action="orders.php">
      <div class="orders-field"><i class="fas fa-magnifying-glass"></i><input class="orders-input" type="search" name="q" value="<?= e($search) ?>" placeholder="جستجو: شماره سفارش، نام، موبایل یا ایمیل"></div>
      <select class="orders-select" name="status"><?php foreach ($statusOptions as $key=>$label): ?><option value="<?= e($key) ?>" <?= $status===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      <select class="orders-select" name="period">
        <option value="all" <?= $period==='all'?'selected':'' ?>>همه تاریخ‌ها</option>
        <option value="today" <?= $period==='today'?'selected':'' ?>>امروز</option>
        <option value="7d" <?= $period==='7d'?'selected':'' ?>>۷ روز اخیر</option>
        <option value="30d" <?= $period==='30d'?'selected':'' ?>>۳۰ روز اخیر</option>
      </select>
      <button class="orders-filter-btn" type="submit"><i class="fas fa-filter ms-1"></i> اعمال فیلتر</button>
      <?php if ($search !== '' || $status !== 'all' || $period !== 'all'): ?><a class="orders-filter-clear" href="orders.php">پاک کردن</a><?php endif; ?>
    </form>
  </section>

  <section class="orders-panel">
    <div class="orders-panel-head">
      <div><div class="orders-panel-title">لیست سفارش‌ها</div><div class="orders-panel-meta"><?= e(ordersFaDigits((string)$totalOrders)) ?> نتیجه مطابق فیلتر فعلی</div></div>
      <div class="orders-panel-meta">آخرین بروزرسانی: <?= e(ordersFaDigits(date('H:i'))) ?></div>
    </div>

    <?php if ($listRes['error']): ?>
      <div class="alert alert-danger m-3 mb-0">خطا در دریافت سفارش‌ها: <?= e((string)$listRes['error']) ?></div>
    <?php elseif (!$orders): ?>
      <div class="orders-empty"><i class="fas fa-inbox"></i><strong>سفارشی پیدا نشد</strong><span>فیلترها را تغییر بده یا دوباره تازه‌سازی کن.</span></div>
    <?php else: ?>
      <div class="table-responsive orders-desktop-table">
        <table class="table orders-table align-middle">
          <thead><tr><th>سفارش</th><th>مشتری</th><th>شهر</th><th>پرداخت</th><th>وضعیت</th><th>مبلغ</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($orders as $order):
            [$statusLabel,$statusClass,$statusIcon] = ordersStatusMeta((string)($order['status'] ?? ''));
            $billing = (array)($order['billing'] ?? []);
            $phone = trim((string)($billing['phone'] ?? ''));
            $city = trim((string)($billing['city'] ?? ''));
          ?>
            <tr>
              <td><a class="order-number" href="orders.php?view=<?= (int)$order['id'] ?>">#<?= e(ordersFaDigits((string)$order['id'])) ?></a><div class="order-sub"><?= e(ordersFormatDate((string)($order['date_created'] ?? ''))) ?></div></td>
              <td><div class="order-customer"><?= e(ordersCustomerName($order)) ?></div><div class="order-sub"><?= $phone !== '' ? e(ordersFaDigits($phone)) : 'بدون شماره' ?></div></td>
              <td><?= $city !== '' ? e($city) : '—' ?></td>
              <td><div><?= e((string)($order['payment_method_title'] ?? '—')) ?></div><div class="order-sub"><?= !empty($order['date_paid']) ? 'پرداخت‌شده' : 'پرداخت ثبت نشده' ?></div></td>
              <td><span class="order-status <?= e($statusClass) ?>"><i class="fas <?= e($statusIcon) ?>"></i><?= e($statusLabel) ?></span></td>
              <td class="order-amount"><?= e(formatPrice($order['total'] ?? 0)) ?></td>
              <td><a class="order-view" href="orders.php?view=<?= (int)$order['id'] ?>" title="جزئیات"><i class="fas fa-chevron-left"></i></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="orders-mobile-list">
        <?php foreach ($orders as $order):
          [$statusLabel,$statusClass,$statusIcon] = ordersStatusMeta((string)($order['status'] ?? ''));
          $billing = (array)($order['billing'] ?? []);
          $phone = trim((string)($billing['phone'] ?? ''));
        ?>
          <article class="order-mobile-card">
            <div class="order-mobile-top"><a class="order-mobile-id" href="orders.php?view=<?= (int)$order['id'] ?>">سفارش #<?= e(ordersFaDigits((string)$order['id'])) ?></a><span class="order-status <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></div>
            <div class="order-mobile-customer"><?= e(ordersCustomerName($order)) ?></div>
            <div class="order-sub mb-2"><?= e(ordersFormatDate((string)($order['date_created'] ?? ''))) ?></div>
            <div class="order-mobile-row"><span>موبایل</span><strong><?= $phone !== '' ? e(ordersFaDigits($phone)) : 'ثبت نشده' ?></strong></div>
            <div class="order-mobile-row"><span>پرداخت</span><strong><?= e((string)($order['payment_method_title'] ?? '—')) ?></strong></div>
            <div class="order-mobile-row"><span>مبلغ</span><strong><?= e(formatPrice($order['total'] ?? 0)) ?></strong></div>
            <div class="order-mobile-actions"><a class="order-mobile-details" href="orders.php?view=<?= (int)$order['id'] ?>"><i class="fas fa-eye"></i> مشاهده جزئیات</a><?php if ($phone !== ''): ?><a class="order-mobile-call" href="tel:<?= e($phone) ?>"><i class="fas fa-phone"></i></a><?php endif; ?></div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <nav class="orders-pagination" aria-label="صفحه‌بندی سفارش‌ها">
        <a class="orders-page <?= $page<=1?'disabled':'' ?>" href="<?= e(ordersQueryUrl(['page'=>max(1,$page-1)])) ?>"><i class="fas fa-chevron-right"></i></a>
        <div class="orders-pages">
          <?php
          $startPage=max(1,$page-2); $endPage=min($totalPages,$page+2);
          for($i=$startPage;$i<=$endPage;$i++):
          ?><a class="orders-page <?= $i===$page?'active':'' ?>" href="<?= e(ordersQueryUrl(['page'=>$i])) ?>"><?= e(ordersFaDigits((string)$i)) ?></a><?php endfor; ?>
        </div>
        <a class="orders-page <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(ordersQueryUrl(['page'=>min($totalPages,$page+1)])) ?>"><i class="fas fa-chevron-left"></i></a>
        <div class="orders-page-info">صفحه <?= e(ordersFaDigits((string)$page)) ?> از <?= e(ordersFaDigits((string)$totalPages)) ?></div>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
