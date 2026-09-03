<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$wc = new WooCommerceClient();
$sync = new BasalamSync();
$basalam = new BasalamClient();

$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim((string)($_GET['s'] ?? ''));
$statusFilter = trim((string)($_GET['sync_status'] ?? ''));

$products = [];
$totalPages = 1;
$total = 0;
$error = null;

if (!$wc->isConfigured()) {
    $error = 'اتصال ووکامرس تنظیم نشده است.';
} else {
    $params = [
        'page' => $page,
        'per_page' => 20,
        'orderby' => 'date',
        'order' => 'desc',
    ];
    if ($search !== '') $params['search'] = $search;

    $res = $wc->getProducts($params);
    if ($res['error']) {
        $error = $res['error'];
    } else {
        $products = $res['body'];
        $totalPages = (int)($res['headers']['total_pages'] ?? 1);
        $total = (int)($res['headers']['total'] ?? count($products));
    }
}

$maps = $sync->getProductMaps(array_column($products, 'id'));
if ($statusFilter !== '') {
    $products = array_values(array_filter($products, function (array $product) use ($maps, $statusFilter) {
        $id = (int)($product['id'] ?? 0);
        $status = (string)($maps[$id]['sync_status'] ?? 'not_synced');
        return $status === $statusFilter;
    }));
}

function basalamStatusMeta(?array $map): array
{
    if (!$map || (int)($map['basalam_product_id'] ?? 0) <= 0) {
        return ['سینک نشده', 'secondary'];
    }

    return match ((string)($map['sync_status'] ?? 'pending')) {
        'synced' => ['سینک شده', 'success'],
        'partial' => ['نیاز به بررسی', 'warning'],
        'error' => ['خطا', 'danger'],
        'unmatched' => ['ناهماهنگ', 'warning'],
        default => ['در انتظار', 'secondary'],
    };
}

function basalamSyncListUrl(int $targetPage): string
{
    $qs = $_GET;
    $qs['page'] = max(1, $targetPage);
    return '?' . http_build_query($qs);
}

$pageTitle = 'سینک محصولات باسلام';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h3 class="mb-1">سینک محصولات WooCommerce → باسلام</h3>
    <div class="text-muted small"><?= e($total) ?> محصول در WooCommerce</div>
  </div>
  <?php if (Auth::isAdmin()): ?>
    <a class="btn btn-outline-primary" href="basalam.php">تنظیمات باسلام</a>
  <?php endif; ?>
</div>

<?php if (!$basalam->isConfigured()): ?>
  <div class="alert alert-warning">
    اتصال باسلام هنوز تنظیم نشده است.
    <?php if (Auth::isAdmin()): ?><a href="basalam.php">تنظیم اتصال</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php else: ?>
  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label small">جستجوی محصول Woo</label>
          <input type="search" name="s" class="form-control" value="<?= e($search) ?>" placeholder="نام محصول...">
        </div>
        <div class="col-8 col-md-4">
          <label class="form-label small">وضعیت سینک در این صفحه</label>
          <select name="sync_status" class="form-select">
            <option value="">همه</option>
            <option value="not_synced" <?= $statusFilter === 'not_synced' ? 'selected' : '' ?>>سینک نشده</option>
            <option value="synced" <?= $statusFilter === 'synced' ? 'selected' : '' ?>>سینک شده</option>
            <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>نیاز به بررسی</option>
            <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>خطا</option>
          </select>
        </div>
        <div class="col-4 col-md-2 d-grid">
          <button class="btn btn-outline-primary">فیلتر</button>
        </div>
      </form>
    </div>
  </div>

  <div id="syncNotice"></div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>محصول Woo</th>
            <th>SKU</th>
            <th>نوع</th>
            <th>باسلام</th>
            <th>آخرین سینک</th>
            <th class="text-end">عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $product): ?>
            <?php
              $id = (int)($product['id'] ?? 0);
              $map = $maps[$id] ?? null;
              [$label, $color] = basalamStatusMeta($map);
            ?>
            <tr id="sync-row-<?= $id ?>">
              <td>
                <div class="fw-semibold"><?= e($product['name'] ?? ('#' . $id)) ?></div>
                <div class="small text-muted">Woo #<?= $id ?></div>
              </td>
              <td dir="ltr"><?= e($product['sku'] ?: '-') ?></td>
              <td><?= e($product['type'] ?? '-') ?></td>
              <td>
                <span class="badge text-bg-<?= e($color) ?>" data-role="status"><?= e($label) ?></span>
                <?php if (!empty($map['basalam_product_id'])): ?>
                  <div class="small text-muted mt-1" data-role="basalam-id">#<?= (int)$map['basalam_product_id'] ?></div>
                <?php else: ?>
                  <div class="small text-muted mt-1" data-role="basalam-id"></div>
                <?php endif; ?>
                <?php if (!empty($map['sync_error'])): ?>
                  <div class="small text-danger mt-1 text-wrap" data-role="error"><?= e($map['sync_error']) ?></div>
                <?php else: ?>
                  <div class="small text-danger mt-1 text-wrap" data-role="error"></div>
                <?php endif; ?>
              </td>
              <td class="small text-muted" data-role="time"><?= e($map['last_synced_at'] ?? '-') ?></td>
              <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-primary sync-btn" data-id="<?= $id ?>">سینک</button>
                <button class="btn btn-sm btn-outline-secondary sync-btn" data-id="<?= $id ?>" data-force="1">Force</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= e(basalamSyncListUrl(max(1, $page - 1))) ?>">قبلی</a>
        </li>
        <li class="page-item disabled"><span class="page-link">صفحه <?= $page ?> از <?= $totalPages ?></span></li>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= e(basalamSyncListUrl(min($totalPages, $page + 1))) ?>">بعدی</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  const notice = document.getElementById('syncNotice');

  function showNotice(message, ok) {
    notice.innerHTML = '';
    const box = document.createElement('div');
    box.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
    box.textContent = message;
    notice.appendChild(box);
  }

  document.querySelectorAll('.sync-btn').forEach(button => {
    button.addEventListener('click', function () {
      const id = this.dataset.id;
      const force = this.dataset.force === '1';
      const row = document.getElementById('sync-row-' + id);
      const buttons = row.querySelectorAll('.sync-btn');
      buttons.forEach(btn => btn.disabled = true);

      const fd = new FormData();
      fd.append('id', id);
      if (force) fd.append('force', '1');

      fetch('ajax/basalam_sync_product.php', { method: 'POST', body: fd })
        .then(async response => {
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'سینک ناموفق بود.');

          const status = row.querySelector('[data-role="status"]');
          status.className = 'badge ' + (data.warnings?.length ? 'text-bg-warning' : 'text-bg-success');
          status.textContent = data.warnings?.length ? 'نیاز به بررسی' : 'سینک شده';
          row.querySelector('[data-role="basalam-id"]').textContent = data.basalam_product_id ? '#' + data.basalam_product_id : '';
          row.querySelector('[data-role="error"]').textContent = data.warnings?.join(' | ') || '';
          row.querySelector('[data-role="time"]').textContent = 'همین الان';
          showNotice(data.message || 'سینک انجام شد.', true);
        })
        .catch(error => {
          showNotice(error.message || 'سینک ناموفق بود.', false);
        })
        .finally(() => buttons.forEach(btn => btn.disabled = false));
    });
  });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
