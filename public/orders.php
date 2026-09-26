
<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/OrderShipmentService.php';
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
.shipment-card{border-color:#d6e8df;background:linear-gradient(155deg,#ffffff,#f8fffb)}.shipment-card .order-card-title i{background:#e4f7ed;color:#198754}.shipment-hint{font-size:.75rem;line-height:1.85;color:#617184;margin:-.25rem 0 .95rem}.shipment-current{padding:.9rem;border:1px solid #cde9d8;background:#f2fcf6;border-radius:14px;margin-bottom:1rem;display:grid;gap:.7rem}.shipment-current-row{display:flex;align-items:flex-start;justify-content:space-between;gap:.7rem;font-size:.75rem}.shipment-current-row span{color:#527367}.shipment-current-row strong{color:#164b32;max-width:60%;overflow-wrap:anywhere;text-align:left}.shipment-code{font-size:1rem!important;letter-spacing:.04em;direction:ltr}.shipment-copy{background:#fff;border:1px solid #b9decb;color:#167145;border-radius:8px;padding:.3rem .55rem;font-size:.7rem;font-weight:800}.shipment-form{display:grid;gap:.8rem}.shipment-field{display:grid;gap:.32rem;font-size:.75rem;font-weight:850;color:#374151}.shipment-field select,.shipment-field input{display:block;width:100%;min-height:44px;max-width:100%;border:1px solid #d7dfe6;border-radius:11px;padding:.58rem .68rem;background:#fff;color:#263140;font:inherit;font-weight:650;outline:none}.shipment-field select:focus,.shipment-field input:focus{border-color:#1e9566;box-shadow:0 0 0 3px rgba(30,149,102,.12)}.shipment-submit{width:100%;min-height:45px;border:0;border-radius:12px;background:#168356;color:#fff;font-size:.8rem;font-weight:900;display:flex;align-items:center;justify-content:center;gap:.5rem}.shipment-submit:hover{background:#116a47}.shipment-sms-state{border-radius:10px;background:#f5f7fa;padding:.7rem;color:#536173;font-size:.73rem;line-height:1.8;margin:.8rem 0}.shipment-sms-retry{display:flex;align-items:center;justify-content:center;gap:.4rem;width:100%;min-height:41px;border:1px solid #d28b27;border-radius:11px;color:#935a11;background:#fff9ed;font-weight:850;font-size:.75rem}.shipment-warning{background:#fffbeb;border:1px solid #f4e3af;color:#8d6310;padding:.7rem;border-radius:11px;font-size:.73rem;line-height:1.8;margin-top:.75rem}.shipment-small{font-size:.69rem;color:#718096;line-height:1.8}.shipment-other[hidden]{display:none!important}

.shipment-divider{border:0;border-top:1px dashed #dce6df;margin:1rem 0}
.shipment-link{display:inline-flex!important;align-items:center;justify-content:center;gap:.45rem;padding:.55rem .8rem;border:1px solid #a4d8be;background:#ebfaf1;color:#136840!important;text-decoration:none;border-radius:10px;font-size:.75rem;font-weight:900}
.shipment-link.secondary{border-color:#d7dfea;background:#f4f7fa;color:#263e5c!important}
.shipment-tools{display:flex;flex-wrap:wrap;gap:.5rem;margin:.75rem 0}
.shipment-finance-box{display:grid;gap:.6rem;padding:.8rem;border:1px solid #dde7f0;background:#f7fafc;border-radius:12px;margin:.8rem 0;font-size:.75rem}
.shipment-finance-row{display:flex;align-items:center;justify-content:space-between;gap:.7rem}
.shipment-finance-row span{color:#687785}
.shipment-finance-row strong{text-align:left}
.shipment-finance-row .plus{color:#168356}
.shipment-finance-row .minus{color:#c13b34}
.shipment-file-link{font-size:.75rem;text-decoration:none!important;color:#176c4c!important;font-weight:900;display:inline-flex;gap:.4rem;align-items:center}
.shipment-receipt-image{width:100%;max-height:170px;object-fit:contain;border:1px solid #e2e7e9;border-radius:10px;background:#fff;margin:.55rem 0}
.shipment-cost-note{font-size:.69rem;color:#65758a;line-height:1.9;margin:.3rem 0}


@media(max-width:900px){.order-detail-grid{grid-template-columns:1fr}.order-item{grid-template-columns:48px minmax(0,1fr);}.order-item-image{width:48px;height:62px}.order-item-total{grid-column:2;text-align:right}.order-info-value{max-width:58%}}@media(max-width:520px){.order-detail-hero{padding:1.05rem;border-radius:18px}.order-card{padding:.85rem;border-radius:16px}.order-info-row{display:grid;gap:.25rem}.order-info-value{max-width:none;text-align:right}}
</style>
<div class="orders-shell">
  <a href="orders.php" class="orders-back"><i class="fas fa-arrow-right"></i> بازگشت به سفارش‌ها</a>
  <div class="shipment-tools" style="margin-bottom:1rem">
    <a class="shipment-link secondary" href="order_label.php?view=<?= $viewId ?>" target="_blank" rel="noopener"><i class="fas fa-print"></i> چاپ لیبل پستی A6</a>
    <a class="shipment-link secondary" href="shipping_finance.php"><i class="fas fa-chart-line"></i> گزارش هزینه‌های ارسال</a>
  </div>
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
    $shipCarrier = OrderShipmentService::meta($order, '_baji_ship_carrier');
    $shipOther = OrderShipmentService::meta($order, '_baji_ship_other');
    $shipTracking = OrderShipmentService::meta($order, '_baji_ship_tracking');
    $shipDate = OrderShipmentService::meta($order, '_baji_ship_sent_at');
    $shipSmsState = OrderShipmentService::meta($order, '_baji_ship_sms_state');
    $shipSmsDate = OrderShipmentService::meta($order, '_baji_ship_sms_updated_at');
    $shipSmsId = OrderShipmentService::meta($order, '_baji_ship_sms_id');
    $shipUrl = OrderShipmentService::trackingUrl($shipCarrier, $shipTracking);
    $shipBy = OrderShipmentService::meta($order, '_baji_ship_handover_by');
    $actualCostRaw = OrderShipmentService::meta($order, '_baji_ship_actual_cost_toman');
    $costBy = OrderShipmentService::meta($order, '_baji_ship_cost_recorded_by');
    $costDate = OrderShipmentService::meta($order, '_baji_ship_cost_recorded_at');
    $receiptFile = OrderShipmentService::meta($order, '_baji_ship_receipt_file');
    $receiptName = OrderShipmentService::meta($order, '_baji_ship_receipt_name');
    $receiptAt = OrderShipmentService::meta($order, '_baji_ship_receipt_at');
    $receiptBy = OrderShipmentService::meta($order, '_baji_ship_receipt_by');
    $customerShippingPaid = (int)round((float)($order['shipping_total'] ?? 0));
    $shipCanEdit = in_array((string)($order['status'] ?? ''), ['processing','completed'], true);
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


        <section class="order-card shipment-card" id="shipment">
          <h2 class="order-card-title"><i class="fas fa-truck-fast"></i> ثبت ارسال و کد رهگیری</h2>
          <p class="shipment-hint">شرکت حمل‌ونقل و شناسه مرسوله را وارد کنید. با ثبت ارسال، سفارش‌های «در حال پردازش» در ووکامرس «تکمیل‌شده» می‌شوند و پیامک اطلاع‌رسانی به شماره مشتری ارسال خواهد شد.</p>
          <?php if ($shipTracking !== ''): ?>
          <div class="shipment-current">
            <div class="shipment-current-row"><span>شرکت ارسال</span><strong><?= e(OrderShipmentService::carrierLabel($shipCarrier,$shipOther)) ?></strong></div>
            <div class="shipment-current-row"><span>کد رهگیری</span><strong class="shipment-code"><?= e($shipTracking) ?></strong></div>
            <button type="button" class="shipment-copy" data-code="<?= e($shipTracking) ?>" onclick="navigator.clipboard?.writeText(this.dataset.code)">کپی کد رهگیری <i class="fas fa-copy" aria-hidden="true"></i></button>
            <div class="shipment-current-row"><span>زمان ثبت ارسال</span><strong><?= e(ordersFormatDate($shipDate)) ?></strong></div>
            <div class="shipment-current-row"><span>ثبت‌کننده ارسال</span><strong><?= e($shipBy ?: 'ثبت نشده') ?></strong></div>
            <?php if ($shipUrl !== ''): ?>
              <a href="<?= e($shipUrl) ?>" class="shipment-link" target="_blank" rel="noopener noreferrer"><i class="fas fa-location-dot" aria-hidden="true"></i> رهگیری در سایت شرکت حمل</a>
              <span class="shipment-small">کد رهگیری را در سامانه شرکت وارد کنید؛ برای چاپار لینک شناسه مرسوله را هم شامل می‌شود.</span>
            <?php else: ?>
              <span class="shipment-small">لینک مستقیم تأییدشده برای این شرکت موجود نیست؛ کد را با سامانه شرکت حمل یا پشتیبانی آن پیگیری کنید.</span>
            <?php endif; ?>
          </div>
          <div class="shipment-sms-state"><i class="fas fa-comment-sms" aria-hidden="true"></i> وضعیت پیامک: <strong><?= e(OrderShipmentService::smsLabel($shipSmsState)) ?></strong>
            <?php if ($shipSmsDate !== ''): ?><br><span class="shipment-small">آخرین بررسی: <?= e(ordersFormatDate($shipSmsDate)) ?></span><?php endif; ?>
            <?php if ($shipSmsId !== ''): ?><br><span class="shipment-small">شناسه پیامک پنل: <?= e($shipSmsId) ?></span><?php endif; ?>
          </div>
          <?php if (in_array($shipSmsState, ['failed','no_phone'], true) && $shipCanEdit): ?>
          <form method="post" action="order_ship.php" onsubmit="return confirm('پیامک کد رهگیری این سفارش دوباره برای مشتری ارسال شود؟')">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="retry_sms">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button class="shipment-sms-retry" type="submit"><i class="fas fa-rotate-right" aria-hidden="true"></i> تلاش مجدد ارسال پیامک</button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($shipCanEdit): ?>
          <form method="post" action="order_ship.php" class="shipment-form" style="margin-top:1rem" onsubmit="return confirm('اطلاعات ارسال ثبت شود، وضعیت سفارش تکمیل شود و پیامک حاوی کد رهگیری به مشتری ارسال شود؟')">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="ship">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <label class="shipment-field">شرکت حمل‌ونقل
              <select name="carrier" required id="ship-carrier-<?= (int)$order['id'] ?>" onchange="document.getElementById('ship-other-<?= (int)$order['id'] ?>').hidden=(this.value!=='other');document.getElementById('ship-other-input-<?= (int)$order['id'] ?>').required=(this.value==='other')">
                <option value="">انتخاب روش ارسال...</option>
                <?php foreach (OrderShipmentService::carriers() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $shipCarrier===$key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="shipment-field shipment-other" id="ship-other-<?= (int)$order['id'] ?>" <?= $shipCarrier==='other' ? '' : 'hidden' ?>>نام شرکت حمل‌ونقل
              <input id="ship-other-input-<?= (int)$order['id'] ?>" name="other_carrier" value="<?= e($shipOther) ?>" maxlength="60" placeholder="نام شرکت..." <?= $shipCarrier==='other' ? 'required' : '' ?>>
            </label>
            <label class="shipment-field">کد رهگیری / شناسه مرسوله
              <input name="tracking_code" dir="ltr" inputmode="text" autocomplete="off" required minlength="4" maxlength="64" placeholder="کد درج‌شده روی رسید ارسال" value="<?= e($shipTracking) ?>">
            </label>
            <p class="shipment-small" style="margin:0">شماره موبایل مشتری: <?= $phone !== '' ? e(ordersFaDigits($phone)) : 'ثبت نشده' ?>. مبلغ و اطلاعات پرداخت تغییری نمی‌کند. ثبت مجدد همان کد پیامک تکراری نمی‌فرستد.</p>
            <button class="shipment-submit" type="submit"><i class="fas fa-box-check" aria-hidden="true"></i> <?= $shipTracking !== '' ? 'ذخیره تغییرات ارسال' : 'ثبت ارسال و تکمیل سفارش' ?></button>
          </form>
          <?php else: ?>
          <div class="shipment-warning"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> برای سفارش‌های پرداخت‌نشده، معلق، لغوشده یا مستردشده امکان تکمیل خودکار وجود ندارد؛ ابتدا وضعیت سفارش را بررسی کنید.</div>
          <?php endif; ?>
        </section>


        <?php if ($shipTracking !== ''): ?>
        <section class="order-card">
          <h2 class="order-card-title"><i class="fas fa-coins"></i> هزینه واقعی حمل (حسابداری)</h2>
          <p class="shipment-hint">هزینه‌ای که واقعاً به شرکت حمل‌ونقل پرداخت شده را وارد کن؛ مبلغ کرایه‌ای که مشتری در تسویه‌حساب پرداخته تغییری نمی‌کند. اختلاف این دو، مازاد/کسری حمل است و سود خالص کل سفارش نیست.</p>
          <div class="shipment-finance-box">
            <div class="shipment-finance-row"><span>کرایه دریافتی از مشتری</span><strong><?= e(formatPrice($customerShippingPaid)) ?></strong></div>
            <div class="shipment-finance-row"><span>هزینه واقعی پرداختی</span><strong><?= $actualCostRaw !== '' ? e(formatPrice($actualCostRaw)) : 'هنوز ثبت نشده' ?></strong></div>
            <div class="shipment-finance-row"><span>مازاد / کسری حمل</span>
              <?php if ($actualCostRaw !== ''): $delta=$customerShippingPaid-(int)$actualCostRaw; ?>
              <strong class="<?= $delta >= 0 ? 'plus' : 'minus' ?>"><?= e(formatPrice($delta)) ?></strong>
              <?php else: ?><strong>نامشخص</strong><?php endif; ?>
            </div>
            <?php if ($costBy !== ''): ?><div class="shipment-cost-note">ثبت هزینه توسط <?= e($costBy) ?> · <?= e(ordersFormatDate($costDate)) ?></div><?php endif; ?>
          </div>
          <?php if ($shipCanEdit): ?>
          <form method="post" action="order_ship.php" class="shipment-form" onsubmit="return confirm('هزینه واقعی حمل در سفارش ووکامرس ذخیره شود؟')">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <input type="hidden" name="action" value="save_cost">
            <label class="shipment-field">مبلغ پرداختی به شرکت حمل (تومان)
              <input type="text" name="actual_cost_toman" required inputmode="numeric" pattern="[0-9۰-۹٠-٩,٬، ]+" placeholder="مثلاً ۱۲۰٬۰۰۰" value="<?= e($actualCostRaw) ?>" dir="ltr" maxlength="15">
            </label>
            <button type="submit" class="shipment-submit"><i class="fas fa-floppy-disk"></i> ثبت هزینه واقعی حمل</button>
          </form>
          <?php endif; ?>
          <div class="shipment-tools"><a class="shipment-link secondary" href="shipping_finance.php"><i class="fas fa-table-list"></i> گزارش و خروجی CSV هزینه ارسال</a></div>
        </section>

        <section class="order-card">
          <h2 class="order-card-title"><i class="fas fa-file-image"></i> رسید تحویل به شرکت حمل</h2>
          <?php if ($receiptFile !== ''): ?>
            <div class="shipment-current">
              <a class="shipment-file-link" href="order_receipt.php?view=<?= (int)$order['id'] ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> مشاهده رسید ثبت‌شده<?= $receiptName !== '' ? ': '.e($receiptName) : '' ?></a>
              <?php if (preg_match('/\.(jpg|png|webp)$/D', $receiptFile)): ?>
              <a href="order_receipt.php?view=<?= (int)$order['id'] ?>" target="_blank" rel="noopener"><img class="shipment-receipt-image" loading="lazy" src="order_receipt.php?view=<?= (int)$order['id'] ?>" alt="تصویر رسید ارسال سفارش"></a>
              <?php endif; ?>
              <div class="shipment-small">ثبت‌شده توسط <?= e($receiptBy ?: 'مدیر') ?> · <?= e(ordersFormatDate($receiptAt)) ?></div>
            </div>
          <?php else: ?>
            <p class="shipment-hint">هنوز تصویر یا فایل رسید این سفارش ثبت نشده است.</p>
          <?php endif; ?>
          <?php if ((string)($order['status'] ?? '') === 'completed'): ?>
          <form method="post" action="order_receipt_upload.php" enctype="multipart/form-data" class="shipment-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <label class="shipment-field"><?= $receiptFile !== '' ? 'جایگزینی رسید' : 'بارگذاری رسید پستی / تصویر تحویل' ?>
              <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required>
            </label>
            <p class="shipment-small" style="margin:0">JPG، PNG، WebP یا PDF تا ۲ مگابایت؛ فایل در پوشه خصوصی سرور نگهداری می‌شود و فقط مدیر می‌تواند آن را ببیند.</p>
            <button type="submit" class="shipment-submit"><i class="fas fa-upload"></i> ذخیره رسید ارسال</button>
          </form>
          <?php else: ?>
            <div class="shipment-warning">ابتدا ارسال و تکمیل سفارش را ثبت کنید؛ سپس رسید قابل بارگذاری است.</div>
          <?php endif; ?>
        </section>
        <?php endif; ?>

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
      <div class="shipment-tools" style="margin:0">
        <a class="orders-refresh" href="shipping_finance.php"><i class="fas fa-chart-line"></i> گزارش مالی ارسال</a>
        <a class="orders-refresh" href="<?= e(ordersQueryUrl(['page'=>1])) ?>"><i class="fas fa-rotate"></i> تازه‌سازی سفارش‌ها</a>
      </div>
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
