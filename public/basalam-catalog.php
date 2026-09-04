<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$basalam = new BasalamClient();
$sync = new BasalamSync();

$search = trim((string)($_GET['s'] ?? ''));
$marketFilter = trim((string)($_GET['market_status'] ?? ''));
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
        return [
            'key' => 'available',
            'label' => $name !== '' ? $name : 'در دسترس',
            'color' => 'success',
            'value' => $value,
            'showable' => $showable,
            'available' => $available,
        ];
    }

    if (
        $value === 3568
        || basalamCatalogContains($normalizedName, 'در انتظار')
        || basalamCatalogContains($normalizedName, 'بررسی')
    ) {
        return [
            'key' => 'pending',
            'label' => $name !== '' ? $name : 'در انتظار بررسی/تایید',
            'color' => 'warning',
            'value' => $value,
            'showable' => $showable,
            'available' => $available,
        ];
    }

    if (
        $value === 3567
        || basalamCatalogContains($normalizedName, 'تایید نشده')
        || basalamCatalogContains($normalizedName, 'رد شده')
        || basalamCatalogContains($normalizedName, 'ردشده')
    ) {
        return [
            'key' => 'rejected',
            'label' => $name !== '' ? $name : 'تایید نشده',
            'color' => 'danger',
            'value' => $value,
            'showable' => $showable,
            'available' => $available,
        ];
    }

    if (
        $value === 3790
        || basalamCatalogContains($normalizedName, 'منتشر نشده')
        || basalamCatalogContains($normalizedName, 'عدم انتشار')
    ) {
        return [
            'key' => 'unpublished',
            'label' => $name !== '' ? $name : 'منتشر نشده',
            'color' => 'secondary',
            'value' => $value,
            'showable' => $showable,
            'available' => $available,
        ];
    }

    if ($showable === false && $available === false) {
        return [
            'key' => 'inactive',
            'label' => $name !== '' ? $name : 'غیرفعال / غیرقابل نمایش',
            'color' => 'dark',
            'value' => $value,
            'showable' => $showable,
            'available' => $available,
        ];
    }

    return [
        'key' => 'unknown',
        'label' => $name !== '' ? $name : 'وضعیت نامشخص',
        'color' => 'secondary',
        'value' => $value,
        'showable' => $showable,
        'available' => $available,
    ];
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
    $stmt = Database::get()->query(
        'SELECT wc_product_id, basalam_product_id FROM basalam_product_map WHERE basalam_product_id IS NOT NULL AND basalam_product_id > 0'
    );
    foreach ($stmt->fetchAll() as $row) {
        $basalamId = (int)($row['basalam_product_id'] ?? 0);
        if ($basalamId > 0) $wooByBasalam[$basalamId] = (int)($row['wc_product_id'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('[wc-manager] basalam catalog mapping lookup failed: ' . $e->getMessage());
}

$stats = [
    'all' => count($products),
    'available' => 0,
    'pending' => 0,
    'rejected' => 0,
    'unpublished' => 0,
    'inactive' => 0,
    'unknown' => 0,
];
foreach ($products as $product) {
    $key = (string)($product['_market']['key'] ?? 'unknown');
    if (!array_key_exists($key, $stats)) $key = 'unknown';
    $stats[$key]++;
}

if ($search !== '' || $marketFilter !== '') {
    $products = array_values(array_filter($products, function (array $product) use ($search, $marketFilter) {
        $market = $product['_market'] ?? [];
        if ($marketFilter !== '' && ($market['key'] ?? '') !== $marketFilter) return false;
        if ($search === '') return true;

        $haystack = implode(' ', [
            (string)($product['id'] ?? ''),
            (string)($product['name'] ?? $product['title'] ?? ''),
            (string)($product['sku'] ?? ''),
            (string)($market['label'] ?? ''),
        ]);
        return basalamCatalogContains($haystack, $search);
    }));
}

$pageTitle = 'وضعیت کاتالوگ باسلام';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h3 class="mb-1">وضعیت محصولات در باسلام</h3>
    <div class="text-muted small">وضعیت واقعی انتشار، نمایش و امکان خرید محصولات غرفه</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
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

  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label small">جستجو</label>
          <input type="search" name="s" class="form-control" value="<?= e($search) ?>" placeholder="نام محصول، SKU یا شناسه باسلام...">
        </div>
        <div class="col-8 col-md-4">
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
        <div class="col-4 col-md-2 d-grid">
          <button class="btn btn-primary">فیلتر</button>
        </div>
      </form>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="small text-muted"><?= count($products) ?> محصول نمایش داده می‌شود</div>
    <?php if ($search !== '' || $marketFilter !== ''): ?><a class="small" href="basalam-catalog.php">پاک کردن فیلترها</a><?php endif; ?>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>محصول باسلام</th>
            <th>SKU</th>
            <th>وضعیت بازار</th>
            <th>قابل نمایش</th>
            <th>قابل خرید</th>
            <th>ارتباط با Woo</th>
            <th class="text-end">مشاهده</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">محصولی با این فیلتر پیدا نشد.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $product): ?>
          <?php
            $id = (int)($product['id'] ?? 0);
            $market = $product['_market'] ?? basalamCatalogStatus($product);
            $showable = $market['showable'] ?? null;
            $available = $market['available'] ?? null;
            $wcId = (int)($wooByBasalam[$id] ?? 0);
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($product['name'] ?? $product['title'] ?? ('#' . $id)) ?></div>
              <div class="small text-muted">Basalam #<?= $id ?></div>
            </td>
            <td dir="ltr"><?= e(trim((string)($product['sku'] ?? '')) ?: '-') ?></td>
            <td>
              <span class="badge text-bg-<?= e((string)$market['color']) ?>"><?= e((string)$market['label']) ?></span>
              <?php if ((int)($market['value'] ?? 0) > 0): ?><div class="small text-muted mt-1">کد وضعیت: <?= (int)$market['value'] ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ($showable === null): ?><span class="text-muted">-</span><?php elseif ($showable): ?><span class="badge text-bg-success">بله</span><?php else: ?><span class="badge text-bg-secondary">خیر</span><?php endif; ?>
            </td>
            <td>
              <?php if ($available === null): ?><span class="text-muted">-</span><?php elseif ($available): ?><span class="badge text-bg-success">بله</span><?php else: ?><span class="badge text-bg-secondary">خیر</span><?php endif; ?>
            </td>
            <td>
              <?php if ($wcId > 0): ?><span class="badge text-bg-info">Woo #<?= $wcId ?></span><?php else: ?><span class="text-muted small">مپ نشده</span><?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="https://basalam.com/p/<?= $id ?>" target="_blank" rel="noopener noreferrer">باز کردن</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
