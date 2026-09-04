<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$basalam = new BasalamClient();

$search = trim((string)($_GET['s'] ?? ''));
$marketFilter = trim((string)($_GET['market_status'] ?? ''));
$relationFilter = trim((string)($_GET['relation'] ?? ''));
$products = [];
$error = null;

function basalamCatalogContains(string $haystack, string $needle): bool
{
    if ($needle === '') return true;
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

function basalamCatalogStatus(array $product): array
{
    $status = $product['status'] ?? null;
    $value = 0;
    $name = '';

    if (is_array($status)) {
        $value = (int)($status['value'] ?? 0);
        $name = trim((string)($status['name'] ?? ''));
    } elseif (is_numeric($status)) {
        $value = (int)$status;
    } elseif (is_string($status)) {
        $name = trim($status);
    }

    $hasShowable = array_key_exists('is_showable', $product);
    $hasAvailable = array_key_exists('is_available', $product);
    $showable = $hasShowable ? (bool)$product['is_showable'] : null;
    $available = $hasAvailable ? (bool)$product['is_available'] : null;
    $normalizedName = str_replace(['ي', 'ك'], ['ی', 'ک'], $name);

    if ($value === 2976 || ($showable === true && $available === true)) {
        return ['key'=>'available','label'=>$name !== '' ? $name : 'در دسترس','color'=>'success','value'=>$value,'showable'=>$showable,'available'=>$available];
    }
    if ($value === 3568 || basalamCatalogContains($normalizedName, 'در انتظار') || basalamCatalogContains($normalizedName, 'بررسی')) {
        return ['key'=>'pending','label'=>$name !== '' ? $name : 'در انتظار بررسی/تایید','color'=>'warning','value'=>$value,'showable'=>$showable,'available'=>$available];
    }
    if ($value === 3567 || basalamCatalogContains($normalizedName, 'تایید نشده') || basalamCatalogContains($normalizedName, 'رد شده') || basalamCatalogContains($normalizedName, 'ردشده')) {
        return ['key'=>'rejected','label'=>$name !== '' ? $name : 'تایید نشده','color'=>'danger','value'=>$value,'showable'=>$showable,'available'=>$available];
    }
    if ($value === 3790 || basalamCatalogContains($normalizedName, 'منتشر نشده') || basalamCatalogContains($normalizedName, 'عدم انتشار')) {
        return ['key'=>'unpublished','label'=>$name !== '' ? $name : 'منتشر نشده','color'=>'secondary','value'=>$value,'showable'=>$showable,'available'=>$available];
    }
    if ($showable === false && $available === false) {
        return ['key'=>'inactive','label'=>$name !== '' ? $name : 'غیرفعال / غیرقابل نمایش','color'=>'dark','value'=>$value,'showable'=>$showable,'available'=>$available];
    }
    return ['key'=>'unknown','label'=>$name !== '' ? $name : 'وضعیت نامشخص','color'=>'secondary','value'=>$value,'showable'=>$showable,'available'=>$available];
}

function basalamCatalogPhoto(array $product): string
{
    $photo = is_array($product['photo'] ?? null) ? $product['photo'] : [];
    return (string)($photo['sm'] ?? $photo['xs'] ?? $photo['md'] ?? '');
}

function basalamCatalogNormalizeTitle(string $title): string
{
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = str_replace(["\u{200c}", "\u{200d}", "\u{200e}", "\u{200f}", 'ي', 'ى', 'ك', 'ة', 'ۀ'], ['', '', '', '', 'ی', 'ی', 'ک', 'ه', 'ه'], $title);
    $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    return preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
}

function basalamCatalogNormalizeSku(mixed $sku): string
{
    $sku = trim((string)$sku);
    return function_exists('mb_strtolower') ? mb_strtolower($sku, 'UTF-8') : strtolower($sku);
}

if (!$basalam->isConfigured()) {
    $error = 'اتصال باسلام تنظیم نشده است.';
} else {
    for ($page = 1; $page <= 50; $page++) {
        $res = $basalam->getVendorProducts(['page' => $page, 'per_page' => 100]);
        if ($res['error']) {
            $error = 'خواندن کاتالوگ باسلام ناموفق بود: ' . $res['error'];
            break;
        }

        $body = $res['body'] ?? [];
        $batch = $body['data'] ?? $body['products'] ?? $body;
        if (!is_array($batch) || !$batch) break;

        $count = 0;
        foreach ($batch as $product) {
            if (!is_array($product)) continue;
            $id = (int)($product['id'] ?? 0);
            if ($id <= 0) continue;
            $count++;
            $product['_market'] = basalamCatalogStatus($product);
            $products[] = $product;
        }

        if ($count < 100) break;
    }
}

$wooByBasalam = [];
try {
    $stmt = Database::get()->query('SELECT wc_product_id, basalam_product_id FROM basalam_product_map WHERE basalam_product_id IS NOT NULL AND basalam_product_id > 0');
    foreach ($stmt->fetchAll() as $row) {
        $basalamId = (int)($row['basalam_product_id'] ?? 0);
        if ($basalamId > 0) $wooByBasalam[$basalamId] = (int)($row['wc_product_id'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('[wc-manager] basalam catalog mapping lookup failed: ' . $e->getMessage());
}

$wooSkuIndex = [];
$wooTitleIndex = [];
try {
    $wc = new WooCommerceClient();
    if ($wc->isConfigured()) {
        for ($wooPage = 1; $wooPage <= 50; $wooPage++) {
            $wooRes = $wc->getProducts(['page'=>$wooPage,'per_page'=>100,'orderby'=>'id','order'=>'asc']);
            if ($wooRes['error']) break;
            $wooBatch = is_array($wooRes['body'] ?? null) ? $wooRes['body'] : [];
            foreach ($wooBatch as $wooProduct) {
                if (!is_array($wooProduct)) continue;
                if (!in_array((string)($wooProduct['type'] ?? ''), ['simple','variable'], true)) continue;
                $wooId = (int)($wooProduct['id'] ?? 0);
                if ($wooId <= 0) continue;
                $sku = basalamCatalogNormalizeSku($wooProduct['sku'] ?? '');
                if ($sku !== '') $wooSkuIndex[$sku][] = $wooId;
                $title = basalamCatalogNormalizeTitle((string)($wooProduct['name'] ?? ''));
                if ($title !== '') $wooTitleIndex[$title][] = $wooId;
            }
            $wooTotalPages = max(1, (int)($wooRes['headers']['total_pages'] ?? 1));
            if ($wooPage >= $wooTotalPages || count($wooBatch) < 100) break;
        }
    }
} catch (Throwable $e) {
    error_log('[wc-manager] basalam catalog Woo relation lookup failed: ' . $e->getMessage());
}

$relationStats = ['linked'=>0,'candidate'=>0,'basalam_only'=>0,'unlinked'=>0];
foreach ($products as &$product) {
    $id = (int)($product['id'] ?? 0);
    $linkedWoo = (int)($wooByBasalam[$id] ?? 0);
    if ($linkedWoo > 0) {
        $product['_relation'] = ['key'=>'linked','label'=>'متصل به سایت','woo_id'=>$linkedWoo,'method'=>'map'];
        $relationStats['linked']++;
        continue;
    }

    $candidateWoo = 0;
    $method = '';
    $sku = basalamCatalogNormalizeSku($product['sku'] ?? '');
    if ($sku !== '' && count($wooSkuIndex[$sku] ?? []) === 1) {
        $candidateWoo = (int)$wooSkuIndex[$sku][0];
        $method = 'SKU';
    }
    if ($candidateWoo <= 0) {
        $title = basalamCatalogNormalizeTitle((string)($product['name'] ?? $product['title'] ?? ''));
        if ($title !== '' && count($wooTitleIndex[$title] ?? []) === 1) {
            $candidateWoo = (int)$wooTitleIndex[$title][0];
            $method = 'عنوان دقیق';
        }
    }

    if ($candidateWoo > 0) {
        $product['_relation'] = ['key'=>'candidate','label'=>'در سایت هست؛ ادغام نشده','woo_id'=>$candidateWoo,'method'=>$method];
        $relationStats['candidate']++;
    } else {
        $product['_relation'] = ['key'=>'basalam_only','label'=>'فقط در باسلام / معادل مطمئن پیدا نشد','woo_id'=>0,'method'=>''];
        $relationStats['basalam_only']++;
    }
    $relationStats['unlinked']++;
}
unset($product);

$stats = ['all'=>count($products),'available'=>0,'pending'=>0,'rejected'=>0,'unpublished'=>0,'inactive'=>0,'unknown'=>0];
foreach ($products as $product) {
    $key = (string)($product['_market']['key'] ?? 'unknown');
    if (!array_key_exists($key, $stats)) $key = 'unknown';
    $stats[$key]++;
}

if ($search !== '' || $marketFilter !== '' || $relationFilter !== '') {
    $products = array_values(array_filter($products, function (array $product) use ($search, $marketFilter, $relationFilter) {
        $market = $product['_market'] ?? [];
        $relation = $product['_relation'] ?? [];
        if ($marketFilter !== '' && ($market['key'] ?? '') !== $marketFilter) return false;
        if ($relationFilter === 'unlinked' && ($relation['key'] ?? '') === 'linked') return false;
        if ($relationFilter !== '' && $relationFilter !== 'unlinked' && ($relation['key'] ?? '') !== $relationFilter) return false;
        if ($search === '') return true;
        $haystack = implode(' ', [
            (string)($product['id'] ?? ''),
            (string)($product['name'] ?? $product['title'] ?? ''),
            (string)($product['sku'] ?? ''),
            (string)($market['label'] ?? ''),
            (string)($relation['label'] ?? ''),
            (string)($relation['woo_id'] ?? ''),
        ]);
        return basalamCatalogContains($haystack, $search);
    }));
}

$pageTitle = 'وضعیت کاتالوگ باسلام';
require __DIR__ . '/partials/header.php';
?>

<style>
.catalog-thumb{width:58px;height:58px;object-fit:cover;border-radius:10px;background:#f3f4f6;border:1px solid #e9ecef;flex:0 0 58px}
.catalog-product-title{min-width:0}
.catalog-reason-box{max-width:560px;white-space:normal;line-height:1.8}
.catalog-reason-box ul{padding-right:1.2rem;margin-bottom:.5rem}
.catalog-illegal-photo{display:flex;gap:.6rem;align-items:flex-start;padding:.45rem 0;border-top:1px dashed #dee2e6}
.catalog-illegal-photo img{width:52px;height:52px;object-fit:cover;border-radius:8px;background:#f3f4f6}
@media (max-width:767.98px){
  .catalog-table-card{background:transparent!important;border:0!important}
  .catalog-table-card .table-responsive{overflow:visible}
  .basalam-catalog-table{display:block;border:0;background:transparent}
  .basalam-catalog-table thead{display:none}
  .basalam-catalog-table tbody{display:block}
  .basalam-catalog-table tr{display:block;background:#fff;border:1px solid #e6e8eb;border-radius:16px;margin-bottom:12px;padding:13px 14px;box-shadow:0 1px 3px rgba(0,0,0,.03)}
  .basalam-catalog-table tr.empty-row{padding:0}
  .basalam-catalog-table td{display:flex;width:100%;border:0!important;padding:6px 0!important;justify-content:space-between;align-items:flex-start;gap:14px;text-align:right!important;white-space:normal!important}
  .basalam-catalog-table td[data-label]::before{content:attr(data-label);color:#6c757d;font-size:.78rem;font-weight:600;flex:0 0 86px;line-height:1.7}
  .basalam-catalog-table td.catalog-main-cell{display:block;padding-bottom:11px!important;margin-bottom:3px;border-bottom:1px solid #f0f1f2!important}
  .basalam-catalog-table td.catalog-main-cell::before{display:none}
  .basalam-catalog-table td.catalog-detail-cell{display:block}
  .basalam-catalog-table td.catalog-detail-cell::before{display:none}
  .basalam-catalog-table td.catalog-actions-cell{justify-content:flex-start;flex-wrap:wrap;padding-top:10px!important}
  .basalam-catalog-table td.catalog-actions-cell::before{display:none}
  .catalog-reason-box{max-width:none;width:100%;background:#fff8e1;border-radius:10px;padding:10px 11px;margin-top:8px}
  .catalog-filter-actions{display:grid!important;grid-template-columns:1fr 1fr;width:100%}
  .catalog-filter-actions .btn{width:100%}
}
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h3 class="mb-1">وضعیت محصولات در باسلام</h3>
    <div class="text-muted small">وضعیت واقعی انتشار، نمایش، امکان خرید و دلیل رد/عدم انتشار</div>
  </div>
  <div class="d-flex gap-2 flex-wrap catalog-filter-actions">
    <a class="btn btn-outline-primary" href="basalam-products.php">سینک محصولات</a>
    <?php if (Auth::isAdmin()): ?><a class="btn btn-outline-secondary" href="basalam.php">تنظیمات باسلام</a><?php endif; ?>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php else: ?>
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-2"><a class="text-decoration-none" href="?market_status=available"><div class="card h-100"><div class="card-body py-3"><div class="text-muted small">در دسترس</div><div class="fs-4 fw-bold text-success"><?= (int)$stats['available'] ?></div></div></div></a></div>
    <div class="col-6 col-md-2"><a class="text-decoration-none" href="?market_status=pending"><div class="card h-100"><div class="card-body py-3"><div class="text-muted small">در انتظار بررسی/تایید</div><div class="fs-4 fw-bold text-warning"><?= (int)$stats['pending'] ?></div></div></div></a></div>
    <div class="col-6 col-md-2"><a class="text-decoration-none" href="?market_status=unpublished"><div class="card h-100"><div class="card-body py-3"><div class="text-muted small">منتشر نشده</div><div class="fs-4 fw-bold"><?= (int)$stats['unpublished'] ?></div></div></div></a></div>
    <div class="col-6 col-md-2"><a class="text-decoration-none" href="?market_status=inactive"><div class="card h-100"><div class="card-body py-3"><div class="text-muted small">غیرفعال</div><div class="fs-4 fw-bold"><?= (int)$stats['inactive'] ?></div></div></div></a></div>
    <div class="col-6 col-md-2"><a class="text-decoration-none" href="?market_status=rejected"><div class="card h-100"><div class="card-body py-3"><div class="text-muted small">تایید نشده/رد شده</div><div class="fs-4 fw-bold text-danger"><?= (int)$stats['rejected'] ?></div></div></div></a></div>
    <div class="col-6 col-md-2"><a class="text-decoration-none" href="basalam-catalog.php"><div class="card h-100"><div class="card-body py-3"><div class="text-muted small">کل کاتالوگ</div><div class="fs-4 fw-bold"><?= (int)$stats['all'] ?></div></div></div></a></div>
  </div>

  <div class="row g-2 mb-3">
    <div class="col-12 col-md-4"><a class="text-decoration-none" href="?relation=linked"><div class="card h-100 border-success-subtle"><div class="card-body py-3"><div class="text-muted small">متصل به سایت</div><div class="fs-4 fw-bold text-success"><?= (int)$relationStats['linked'] ?></div><div class="small text-muted">مپ Woo ↔ باسلام دارد</div></div></div></a></div>
    <div class="col-12 col-md-4"><a class="text-decoration-none" href="?relation=candidate"><div class="card h-100 border-warning-subtle"><div class="card-body py-3"><div class="text-muted small">در سایت هست؛ ادغام نشده</div><div class="fs-4 fw-bold text-warning"><?= (int)$relationStats['candidate'] ?></div><div class="small text-muted">تطبیق قطعی SKU یا عنوان پیدا شد</div></div></div></a></div>
    <div class="col-12 col-md-4"><a class="text-decoration-none" href="?relation=basalam_only"><div class="card h-100 border-danger-subtle"><div class="card-body py-3"><div class="text-muted small">فقط در باسلام</div><div class="fs-4 fw-bold text-danger"><?= (int)$relationStats['basalam_only'] ?></div><div class="small text-muted">در سایت معادل مطمئن پیدا نشد</div></div></div></a></div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label small">جستجو</label>
          <input type="search" name="s" class="form-control" value="<?= e($search) ?>" placeholder="نام محصول، SKU یا شناسه باسلام...">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">وضعیت در باسلام</label>
          <select name="market_status" class="form-select">
            <option value="">همه وضعیت‌ها</option>
            <option value="available" <?= $marketFilter === 'available' ? 'selected' : '' ?>>در دسترس</option>
            <option value="pending" <?= $marketFilter === 'pending' ? 'selected' : '' ?>>در انتظار بررسی / تایید</option>
            <option value="unpublished" <?= $marketFilter === 'unpublished' ? 'selected' : '' ?>>منتشر نشده</option>
            <option value="inactive" <?= $marketFilter === 'inactive' ? 'selected' : '' ?>>غیرفعال / غیرقابل نمایش</option>
            <option value="rejected" <?= $marketFilter === 'rejected' ? 'selected' : '' ?>>تایید نشده / رد شده</option>
            <option value="unknown" <?= $marketFilter === 'unknown' ? 'selected' : '' ?>>نامشخص</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">ارتباط با سایت</label>
          <select name="relation" class="form-select">
            <option value="">همه ارتباط‌ها</option>
            <option value="linked" <?= $relationFilter === 'linked' ? 'selected' : '' ?>>متصل به سایت</option>
            <option value="unlinked" <?= $relationFilter === 'unlinked' ? 'selected' : '' ?>>همه بدون اتصال</option>
            <option value="candidate" <?= $relationFilter === 'candidate' ? 'selected' : '' ?>>در سایت هست؛ ادغام نشده</option>
            <option value="basalam_only" <?= $relationFilter === 'basalam_only' ? 'selected' : '' ?>>فقط در باسلام</option>
          </select>
        </div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-primary">فیلتر</button></div>
      </form>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="small text-muted"><?= count($products) ?> محصول نمایش داده می‌شود</div>
    <?php if ($search !== '' || $marketFilter !== '' || $relationFilter !== ''): ?><a class="small" href="basalam-catalog.php">پاک کردن فیلترها</a><?php endif; ?>
  </div>

  <div class="card catalog-table-card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 basalam-catalog-table">
        <thead class="table-light">
          <tr>
            <th>محصول باسلام</th><th>SKU</th><th>وضعیت بازار</th><th>قابل نمایش</th><th>قابل خرید</th><th>ارتباط با Woo</th><th>دلیل / جزئیات</th><th class="text-end">مشاهده</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$products): ?>
          <tr class="empty-row"><td colspan="8" class="text-center text-muted py-4">محصولی با این فیلتر پیدا نشد.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $product): ?>
          <?php
            $id = (int)($product['id'] ?? 0);
            $market = $product['_market'] ?? basalamCatalogStatus($product);
            $showable = $market['showable'] ?? null;
            $available = $market['available'] ?? null;
            $wcId = (int)($wooByBasalam[$id] ?? 0);
            $relation = $product['_relation'] ?? ['key'=>'basalam_only','label'=>'فقط در باسلام','woo_id'=>0,'method'=>''];
            $photo = basalamCatalogPhoto($product);
            $needsDetail = ($market['key'] ?? '') !== 'available';
          ?>
          <tr id="catalog-row-<?= $id ?>">
            <td class="catalog-main-cell">
              <div class="d-flex align-items-start gap-3">
                <?php if ($photo !== ''): ?><img class="catalog-thumb" src="<?= e($photo) ?>" alt="" loading="lazy"><?php endif; ?>
                <div class="catalog-product-title">
                  <div class="fw-semibold"><?= e($product['name'] ?? $product['title'] ?? ('#' . $id)) ?></div>
                  <div class="small text-muted">Basalam #<?= $id ?></div>
                </div>
              </div>
            </td>
            <td data-label="SKU" dir="ltr"><?= e(trim((string)($product['sku'] ?? '')) ?: '-') ?></td>
            <td data-label="وضعیت">
              <div>
                <span class="badge text-bg-<?= e((string)$market['color']) ?>"><?= e((string)$market['label']) ?></span>
                <?php if ((int)($market['value'] ?? 0) > 0): ?><div class="small text-muted mt-1">کد: <?= (int)$market['value'] ?></div><?php endif; ?>
              </div>
            </td>
            <td data-label="قابل نمایش"><?php if ($showable === null): ?><span class="text-muted">-</span><?php elseif ($showable): ?><span class="badge text-bg-success">بله</span><?php else: ?><span class="badge text-bg-secondary">خیر</span><?php endif; ?></td>
            <td data-label="قابل خرید"><?php if ($available === null): ?><span class="text-muted">-</span><?php elseif ($available): ?><span class="badge text-bg-success">بله</span><?php else: ?><span class="badge text-bg-secondary">خیر</span><?php endif; ?></td>
            <td data-label="ارتباط با سایت">
              <?php if (($relation['key'] ?? '') === 'linked'): ?>
                <span class="badge text-bg-info">Woo #<?= (int)$relation['woo_id'] ?></span>
                <div class="small text-success mt-1">متصل به سایت</div>
              <?php elseif (($relation['key'] ?? '') === 'candidate'): ?>
                <span class="badge text-bg-warning">Woo #<?= (int)$relation['woo_id'] ?> احتمالی</span>
                <div class="small text-warning-emphasis mt-1">در سایت هست؛ ادغام نشده</div>
                <div class="small text-muted">تطبیق: <?= e((string)($relation['method'] ?? '')) ?></div>
              <?php else: ?>
                <span class="badge text-bg-danger">فقط باسلام</span>
                <div class="small text-muted mt-1">معادل مطمئن در Woo پیدا نشد</div>
              <?php endif; ?>
            </td>
            <td class="catalog-detail-cell" data-label="دلیل">
              <?php if ($needsDetail): ?>
                <button type="button" class="btn btn-sm btn-outline-warning moderation-detail-btn" data-id="<?= $id ?>">نمایش دلیل</button>
                <div class="catalog-reason-box d-none" id="reason-<?= $id ?>"></div>
              <?php else: ?><span class="text-muted small">-</span><?php endif; ?>
            </td>
            <td class="catalog-actions-cell text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="https://basalam.com/p/<?= $id ?>" target="_blank" rel="noopener noreferrer">باز کردن در باسلام</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script>
(function () {
  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));
  }

  function renderDetail(box, data) {
    const reasons = Array.isArray(data.reasons) ? data.reasons : [];
    const illegal = Array.isArray(data.illegal_photos) ? data.illegal_photos : [];
    let html = '';
    if (data.message) html += '<div class="small mb-2">' + escapeHtml(data.message).replace(/\n/g,'<br>') + '</div>';
    if (reasons.length) {
      html += '<div class="fw-semibold small mb-1">دلیل باسلام:</div><ul class="small">';
      reasons.forEach(r => { html += '<li>' + escapeHtml(r).replace(/\n/g,'<br>') + '</li>'; });
      html += '</ul>';
    } else {
      html += '<div class="small text-muted">باسلام دلیل جزئی دیگری در API برنگرداند.</div>';
    }
    if (data.rejected_at) html += '<div class="small text-muted mb-2">زمان رد/بررسی: ' + escapeHtml(data.rejected_at) + '</div>';
    if (illegal.length) {
      html += '<details class="small"><summary>تصاویر مشکل‌دار (' + illegal.length + ')</summary><div class="mt-2">';
      illegal.forEach(item => {
        html += '<div class="catalog-illegal-photo">';
        if (item.thumbnail) html += '<img src="' + escapeHtml(item.thumbnail) + '" alt="">';
        html += '<div><div class="text-muted">File #' + escapeHtml(item.file_id) + '</div>';
        (item.reasons || []).forEach(r => { html += '<div>' + escapeHtml(r).replace(/\n/g,'<br>') + '</div>'; });
        html += '</div></div>';
      });
      html += '</div></details>';
    }
    box.innerHTML = html;
    box.classList.remove('d-none');
  }

  document.querySelectorAll('.moderation-detail-btn').forEach(button => {
    button.addEventListener('click', async function () {
      const id = this.dataset.id;
      const box = document.getElementById('reason-' + id);
      if (!box) return;
      if (box.dataset.loaded === '1') {
        box.classList.toggle('d-none');
        this.textContent = box.classList.contains('d-none') ? 'نمایش دلیل' : 'بستن جزئیات';
        return;
      }
      const old = this.textContent;
      this.disabled = true;
      this.textContent = 'در حال دریافت...';
      try {
        const response = await fetch('ajax/basalam_product_status_detail.php?id=' + encodeURIComponent(id));
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'دریافت جزئیات ناموفق بود.');
        renderDetail(box, data);
        box.dataset.loaded = '1';
        this.textContent = 'بستن جزئیات';
      } catch (error) {
        box.innerHTML = '<div class="text-danger small">' + escapeHtml(error.message || 'دریافت جزئیات ناموفق بود.') + '</div>';
        box.classList.remove('d-none');
        this.textContent = old;
      } finally {
        this.disabled = false;
      }
    });
  });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
