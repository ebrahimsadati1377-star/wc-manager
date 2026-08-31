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

require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h3 class="mb-0">محصولات <?php if ($total): ?><span class="text-muted fs-6">(<?= e($total) ?> محصول)</span><?php endif; ?></h3>
  <a href="product_edit.php" class="btn btn-primary">➕ افزودن محصول جدید</a>
</div>

<?php if ($loadError): ?>
  <div class="alert alert-danger"><?= e($loadError) ?></div>
<?php else: ?>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label small mb-1">جستجو</label>
        <input type="text" name="s" class="form-control" placeholder="نام یا SKU محصول..." value="<?= e($search) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">دسته‌بندی</label>
        <select name="category" class="form-select">
          <option value="0">همه دسته‌ها</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">وضعیت</label>
        <select name="status" class="form-select">
          <option value="">همه</option>
          <option value="publish" <?= $status === 'publish' ? 'selected' : '' ?>>منتشرشده</option>
          <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
          <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>در انتظار بررسی</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">نوع</label>
        <select name="type" class="form-select">
          <option value="">همه</option>
          <option value="simple" <?= $type === 'simple' ? 'selected' : '' ?>>ساده</option>
          <option value="variable" <?= $type === 'variable' ? 'selected' : '' ?>>متغیر</option>
        </select>
      </div>
      <div class="col-md-1">
        <button class="btn btn-outline-primary w-100">فیلتر</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
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
        <tr>
          <td>
            <?php if (!empty($p['images'][0]['src'])): ?>
              <img src="<?= e($p['images'][0]['src']) ?>" class="thumb-sm">
            <?php else: ?>
              <div class="thumb-sm bg-light d-flex align-items-center justify-content-center text-muted">—</div>
            <?php endif; ?>
          </td>
          <td>
            <a href="product_edit.php?id=<?= (int)$p['id'] ?>" class="text-decoration-none fw-semibold"><?= e($p['name']) ?></a>
          </td>
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
            <?php else: ?>
              <?php if ($p['on_sale']): ?>
                <span class="text-decoration-line-through text-muted small"><?= formatPrice($p['regular_price']) ?></span>
                <span class="text-danger fw-semibold"><?= formatPrice($p['sale_price']) ?></span>
              <?php else: ?>
                <?= formatPrice($p['regular_price']) ?>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($p['manage_stock']): ?>
              <?= e($p['stock_quantity'] ?? '-') ?>
            <?php else: ?>
              <span class="small text-muted"><?= $p['stock_status'] === 'instock' ? 'موجود' : 'ناموجود' ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $statusMap = ['publish' => ['منتشرشده', 'success'], 'draft' => ['پیش‌نویس', 'secondary'], 'pending' => ['در انتظار', 'warning']];
              [$sLabel, $sColor] = $statusMap[$p['status']] ?? [$p['status'], 'light'];
            ?>
            <span class="badge text-bg-<?= $sColor ?>"><?= e($sLabel) ?></span>
          </td>
          <td class="text-end">
            <a href="product_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">ویرایش</a>
            <?php if (!empty($p['permalink'])): ?>
              <a href="<?= e($p['permalink']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">مشاهده</a>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteProduct(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['name'])) ?>')">حذف</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($products)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">محصولی یافت نشد.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination justify-content-center">
    <?php
    $qs = $_GET;
    for ($i = 1; $i <= $totalPages; $i++):
        $qs['page'] = $i;
    ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>">
        <a class="page-link" href="?<?= http_build_query($qs) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<script>
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
