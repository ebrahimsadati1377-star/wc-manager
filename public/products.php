<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$wc = new WooCommerceClient();
$pageTitle = 'محصولات';

$products = [];
$loadError = null;
$totalPages = 1;
$total = 0;

$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['s'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$status = trim($_GET['status'] ?? '');
$type = trim($_GET['type'] ?? '');

$categories = [];
$basalamMaps = [];

if (!$wc->isConfigured()) {
    $loadError = 'اتصال به ووکامرس تنظیم نشده است.';
} else {
    $params = ['page' => $page, 'per_page' => 20, 'orderby' => 'date', 'order' => 'desc'];
    if ($search !== '') $params['search'] = $search;
    if ($categoryId > 0) $params['category'] = $categoryId;
    if ($status !== '') $params['status'] = $status;
    if ($type !== '') $params['type'] = $type;

    $res = $wc->getProducts($params);
    if ($res['error']) {
        $loadError = $res['error'];
    } else {
        $products = $res['body'];
        $totalPages = $res['headers']['total_pages'] ?? 1;
        $total = $res['headers']['total'] ?? count($products);
    }

    $catRes = $wc->getCategories(['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
    if (!$catRes['error']) {
        $categories = $catRes['body'];
    }
}

if ($products) {
    try {
        $basalamSync = new BasalamSync();
        $basalamMaps = $basalamSync->getProductMaps(array_column($products, 'id'));
    } catch (Throwable $e) {
        $basalamMaps = [];
    }
}

function productListUrl(int $targetPage): string
{
    $qs = $_GET;
    $qs['page'] = max(1, $targetPage);
    return '?' . http_build_query($qs);
}

function productStatusMeta(array $product): array
{
    $statusMap = [
        'publish' => ['منتشرشده', 'success'],
        'draft' => ['پیش‌نویس', 'secondary'],
        'pending' => ['در انتظار', 'warning'],
    ];
    return $statusMap[$product['status'] ?? ''] ?? [($product['status'] ?? '-'), 'light'];
}

require __DIR__ . '/partials/header.php';
?>

<style>
.products-heading .btn { min-height: 44px; }
.product-mobile-list { display: none; }
.product-mobile-card {
    border: 1px solid #e9ecef;
    border-radius: 1rem;
    background: #fff;
    padding: 1rem;
    box-shadow: 0 .125rem .35rem rgba(0, 0, 0, .04);
}
.product-mobile-top { display: flex; gap: .85rem; align-items: flex-start; }
.product-mobile-image,
.product-mobile-placeholder {
    width: 82px;
    height: 82px;
    border-radius: .8rem;
    object-fit: cover;
    flex: 0 0 82px;
}
.product-mobile-title {
    font-weight: 700;
    color: #212529;
    text-decoration: none;
    line-height: 1.7;
    display: block;
}
.product-mobile-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .65rem;
    margin-top: 1rem;
}
.product-mobile-meta-item {
    background: #f8f9fa;
    border-radius: .75rem;
    padding: .7rem .8rem;
    min-width: 0;
}
.product-mobile-meta-label { color: #6c757d; font-size: .75rem; margin-bottom: .2rem; }
.product-mobile-meta-value { font-weight: 600; font-size: .9rem; overflow-wrap: anywhere; }
.product-mobile-actions {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: .5rem;
    margin-top: 1rem;
}
.product-mobile-actions .btn {
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
}
.products-filter-card { border: 0; box-shadow: 0 .125rem .35rem rgba(0,0,0,.04); }
.mobile-pagination { display: none; }
.product-basalam-sync { margin-top: .75rem; }
.product-basalam-sync .btn { min-height: 44px; width: 100%; }
.product-basalam-sync-time { margin-top: .35rem; color: #6c757d; font-size: .78rem; text-align: center; }
.desktop-basalam-sync { margin-top: .5rem; }
.desktop-basalam-sync-time { margin-top: .25rem; color: #6c757d; font-size: .72rem; }

@media (max-width: 767.98px) {
    .products-heading { align-items: stretch !important; flex-direction: column; }
    .products-heading .btn { width: 100%; }
    .products-filter-card .card-body { padding: 1rem; }
    .products-desktop-table { display: none; }
    .product-mobile-list { display: grid; gap: .85rem; }
    .desktop-pagination { display: none; }
    .mobile-pagination { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    .mobile-pagination .btn { min-height: 44px; flex: 0 0 auto; }
    .mobile-page-current { color: #6c757d; font-size: .9rem; text-align: center; flex: 1 1 auto; }
}

@media (max-width: 380px) {
    .product-mobile-meta { grid-template-columns: 1fr; }
    .product-mobile-actions { grid-template-columns: 1fr; }
}
</style>

<div class="products-heading d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h3 class="mb-0">محصولات <?php if ($total): ?><span class="text-muted fs-6">(<?= e($total) ?> محصول)</span><?php endif; ?></h3>
  <a href="product_edit.php" class="btn btn-primary"><i class="fas fa-plus ms-1"></i> افزودن محصول جدید</a>
</div>

<?php if ($loadError): ?>
  <div class="alert alert-danger"><?= e($loadError) ?></div>
<?php else: ?>

<div class="card products-filter-card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1">جستجو</label>
        <input type="search" name="s" class="form-control" placeholder="نام یا SKU محصول..." value="<?= e($search) ?>">
      </div>
      <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label small mb-1">دسته‌بندی</label>
        <select name="category" class="form-select">
          <option value="0">همه دسته‌ها</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-sm-3 col-md-2">
        <label class="form-label small mb-1">وضعیت</label>
        <select name="status" class="form-select">
          <option value="">همه</option>
          <option value="publish" <?= $status === 'publish' ? 'selected' : '' ?>>منتشرشده</option>
          <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
          <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>در انتظار بررسی</option>
        </select>
      </div>
      <div class="col-6 col-sm-3 col-md-2">
        <label class="form-label small mb-1">نوع</label>
        <select name="type" class="form-select">
          <option value="">همه</option>
          <option value="simple" <?= $type === 'simple' ? 'selected' : '' ?>>ساده</option>
          <option value="variable" <?= $type === 'variable' ? 'selected' : '' ?>>متغیر</option>
        </select>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-outline-primary">فیلتر</button>
      </div>
    </form>
  </div>
</div>

<?php if (empty($products)): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">محصولی یافت نشد.</div>
  </div>
<?php else: ?>

<div class="card products-desktop-table">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>تصویر</th>
          <th>نام محصول</th>
          <th>نوع</th>
          <th>SKU</th>
          <th>قیمت</th>
          <th>موجودی</th>
          <th>وضعیت</th>
          <th class="text-end">عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
        <?php
          [$sLabel, $sColor] = productStatusMeta($p);
          $bMap = $basalamMaps[(int)$p['id']] ?? null;
          $bMapped = !empty($bMap['basalam_product_id']);
          $bLastSync = trim((string)($bMap['last_synced_at'] ?? ''));
        ?>
        <tr>
          <td>
            <?php if (!empty($p['images'][0]['src'])): ?>
              <img src="<?= e($p['images'][0]['src']) ?>" class="thumb-sm" alt="<?= e($p['name']) ?>">
            <?php else: ?>
              <div class="thumb-sm bg-light d-flex align-items-center justify-content-center text-muted">—</div>
            <?php endif; ?>
          </td>
          <td><a href="product_edit.php?id=<?= (int)$p['id'] ?>" class="text-decoration-none fw-semibold"><?= e($p['name']) ?></a></td>
          <td>
            <?php if ($p['type'] === 'variable'): ?>
              <span class="badge badge-type-variable">متغیر</span>
            <?php else: ?>
              <span class="badge badge-type-simple">ساده</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted" dir="ltr"><?= e($p['sku'] ?: '-') ?></td>
          <td>
            <?php if ($p['type'] === 'variable'): ?>
              <span class="small text-muted"><?= e($p['price'] !== '' ? formatPrice($p['price']) : 'بسته به تنوع') ?></span>
            <?php elseif ($p['on_sale']): ?>
              <span class="text-decoration-line-through text-muted small"><?= formatPrice($p['regular_price']) ?></span>
              <span class="text-danger fw-semibold"><?= formatPrice($p['sale_price']) ?></span>
            <?php else: ?>
              <?= formatPrice($p['regular_price']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($p['manage_stock']): ?>
              <?= e($p['stock_quantity'] ?? '-') ?>
            <?php else: ?>
              <span class="small text-muted"><?= $p['stock_status'] === 'instock' ? 'موجود' : 'ناموجود' ?></span>
            <?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $sColor ?>"><?= e($sLabel) ?></span></td>
          <td class="text-end text-nowrap">
            <a href="product_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">ویرایش</a>
            <?php if (!empty($p['permalink'])): ?>
              <a href="<?= e($p['permalink']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">مشاهده</a>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteProduct(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['name'])) ?>')">حذف</button>
            <div class="desktop-basalam-sync">
              <button type="button" class="btn btn-sm btn-outline-success basalam-update-btn" data-id="<?= (int)$p['id'] ?>" <?= $bMapped ? '' : 'disabled' ?> title="<?= $bMapped ? 'ارسال بروزرسانی به باسلام' : 'این محصول هنوز به باسلام مپ نشده است' ?>">
                <i class="fas fa-sync-alt ms-1"></i> بروزرسانی باسلام
              </button>
              <div class="desktop-basalam-sync-time" data-basalam-last-sync-id="<?= (int)$p['id'] ?>">
                آخرین بروزرسانی: <?= e($bLastSync !== '' ? $bLastSync : 'انجام نشده') ?>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="product-mobile-list">
  <?php foreach ($products as $p): ?>
    <?php
      [$sLabel, $sColor] = productStatusMeta($p);
      $priceLabel = '';
      if ($p['type'] === 'variable') {
          $priceLabel = $p['price'] !== '' ? formatPrice($p['price']) : 'بسته به تنوع';
      } elseif ($p['on_sale']) {
          $priceLabel = formatPrice($p['sale_price']);
      } else {
          $priceLabel = formatPrice($p['regular_price']);
      }
      $stockLabel = $p['manage_stock']
          ? (string)($p['stock_quantity'] ?? '-')
          : ($p['stock_status'] === 'instock' ? 'موجود' : 'ناموجود');
      $typeLabel = $p['type'] === 'variable' ? 'متغیر' : 'ساده';
      $bMap = $basalamMaps[(int)$p['id']] ?? null;
      $bMapped = !empty($bMap['basalam_product_id']);
      $bLastSync = trim((string)($bMap['last_synced_at'] ?? ''));
    ?>
    <article class="product-mobile-card">
      <div class="product-mobile-top">
        <?php if (!empty($p['images'][0]['src'])): ?>
          <img src="<?= e($p['images'][0]['src']) ?>" class="product-mobile-image" alt="<?= e($p['name']) ?>">
        <?php else: ?>
          <div class="product-mobile-placeholder bg-light d-flex align-items-center justify-content-center text-muted">—</div>
        <?php endif; ?>
        <div class="flex-grow-1 min-w-0">
          <a href="product_edit.php?id=<?= (int)$p['id'] ?>" class="product-mobile-title"><?= e($p['name']) ?></a>
          <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
            <span class="badge <?= $p['type'] === 'variable' ? 'badge-type-variable' : 'badge-type-simple' ?>"><?= e($typeLabel) ?></span>
            <span class="badge text-bg-<?= $sColor ?>"><?= e($sLabel) ?></span>
          </div>
        </div>
      </div>

      <div class="product-mobile-meta">
        <div class="product-mobile-meta-item">
          <div class="product-mobile-meta-label">قیمت</div>
          <div class="product-mobile-meta-value"><?= e($priceLabel) ?></div>
        </div>
        <div class="product-mobile-meta-item">
          <div class="product-mobile-meta-label">موجودی</div>
          <div class="product-mobile-meta-value"><?= e($stockLabel) ?></div>
        </div>
        <div class="product-mobile-meta-item">
          <div class="product-mobile-meta-label">SKU</div>
          <div class="product-mobile-meta-value" dir="ltr"><?= e($p['sku'] ?: '-') ?></div>
        </div>
        <div class="product-mobile-meta-item">
          <div class="product-mobile-meta-label">شناسه</div>
          <div class="product-mobile-meta-value">#<?= (int)$p['id'] ?></div>
        </div>
      </div>

      <div class="product-mobile-actions">
        <a href="product_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-primary"><i class="fas fa-edit"></i> ویرایش</a>
        <?php if (!empty($p['permalink'])): ?>
          <a href="<?= e($p['permalink']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary"><i class="fas fa-eye"></i> مشاهده</a>
        <?php else: ?>
          <button class="btn btn-outline-secondary" disabled><i class="fas fa-eye"></i> مشاهده</button>
        <?php endif; ?>
        <button class="btn btn-outline-danger" onclick="deleteProduct(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['name'])) ?>')"><i class="fas fa-trash"></i> حذف</button>
      </div>

      <div class="product-basalam-sync">
        <button type="button" class="btn btn-outline-success basalam-update-btn" data-id="<?= (int)$p['id'] ?>" <?= $bMapped ? '' : 'disabled' ?> title="<?= $bMapped ? 'ارسال بروزرسانی به باسلام' : 'این محصول هنوز به باسلام مپ نشده است' ?>">
          <i class="fas fa-sync-alt"></i> بروزرسانی باسلام
        </button>
        <div class="product-basalam-sync-time" data-basalam-last-sync-id="<?= (int)$p['id'] ?>">
          آخرین بروزرسانی: <?= e($bLastSync !== '' ? $bLastSync : 'انجام نشده') ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3 desktop-pagination" aria-label="صفحه‌بندی محصولات">
  <ul class="pagination justify-content-center flex-wrap">
    <?php
      $start = max(1, $page - 2);
      $end = min($totalPages, $page + 2);
    ?>
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(productListUrl(max(1, $page - 1))) ?>">قبلی</a>
    </li>
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>">
        <a class="page-link" href="<?= e(productListUrl($i)) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(productListUrl(min($totalPages, $page + 1))) ?>">بعدی</a>
    </li>
  </ul>
</nav>

<nav class="mt-3 mobile-pagination" aria-label="صفحه‌بندی موبایل محصولات">
  <a class="btn btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(productListUrl(max(1, $page - 1))) ?>">قبلی</a>
  <div class="mobile-page-current">صفحه <?= $page ?> از <?= $totalPages ?></div>
  <a class="btn btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(productListUrl(min($totalPages, $page + 1))) ?>">بعدی</a>
</nav>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.basalam-update-btn').forEach(button => {
  button.addEventListener('click', async function () {
    const id = this.dataset.id;
    if (!id) return;

    const buttons = document.querySelectorAll('.basalam-update-btn[data-id="' + id + '"]');
    buttons.forEach(btn => btn.disabled = true);
    const originalHtml = this.innerHTML;
    this.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> در حال بروزرسانی...';

    const fd = new FormData();
    fd.append('id', id);
    fd.append('force', '1');
    if (window.CSRF_TOKEN) fd.append('csrf_token', window.CSRF_TOKEN);

    try {
      const response = await fetch('ajax/basalam_sync_product.php', { method: 'POST', body: fd });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'بروزرسانی باسلام ناموفق بود.');

      document.querySelectorAll('[data-basalam-last-sync-id="' + id + '"]').forEach(el => {
        el.textContent = 'آخرین بروزرسانی: همین الان';
      });

      const warningText = Array.isArray(data.warnings) && data.warnings.length
        ? '\n\nهشدار: ' + data.warnings.join(' | ')
        : '';
      alert((data.message || 'بروزرسانی باسلام انجام شد.') + warningText);
    } catch (error) {
      alert(error.message || 'بروزرسانی باسلام ناموفق بود.');
    } finally {
      this.innerHTML = originalHtml;
      buttons.forEach(btn => btn.disabled = false);
    }
  });
});

function deleteProduct(id, name) {
  if (!confirm('آیا از حذف محصول «' + name + '» مطمئن هستید؟ این عملیات غیرقابل بازگشت است.')) return;
  const fd = new FormData();
  fd.append('id', id);
  fd.append('csrf_token', window.CSRF_TOKEN);
  fetch('ajax/product_delete.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) location.reload();
      else alert(data.message);
    });
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
