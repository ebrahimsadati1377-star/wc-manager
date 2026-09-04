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

.unified-product-tabs{display:flex;gap:.5rem;overflow-x:auto;padding:.2rem 0 .8rem;scrollbar-width:none}
.unified-product-tabs::-webkit-scrollbar{display:none}
.unified-product-tab{border:1px solid #dee2e6;background:#fff;border-radius:999px;padding:.65rem .9rem;white-space:nowrap;min-height:44px;font-weight:700;color:#495057}
.unified-product-tab.active{background:#0d6efd;border-color:#0d6efd;color:#fff}
.unified-product-tab .count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;border-radius:999px;background:rgba(0,0,0,.07);font-size:.75rem;margin-right:.35rem;padding:0 .35rem}
.unified-product-tab.active .count{background:rgba(255,255,255,.2)}
.unified-basalam-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:.85rem}
.unified-basalam-card{background:#fff;border:1px solid #e7e9ec;border-radius:1rem;padding:1rem;box-shadow:0 .125rem .35rem rgba(0,0,0,.04)}
.unified-basalam-head{display:flex;gap:.8rem;align-items:flex-start}
.unified-basalam-thumb{width:72px;height:72px;border-radius:.8rem;object-fit:cover;background:#f3f4f6;flex:0 0 72px}
.unified-basalam-title{font-weight:800;line-height:1.65}
.unified-basalam-meta{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.85rem}
.unified-basalam-meta>div{background:#f8f9fa;border-radius:.7rem;padding:.6rem .7rem}
.unified-basalam-meta small{display:block;color:#6c757d;margin-bottom:.15rem}
.unified-basalam-actions{display:flex;gap:.45rem;flex-wrap:wrap;margin-top:.85rem}
.unified-basalam-actions .btn{min-height:40px}
.unified-detail-box{background:#fff8e1;border-radius:.7rem;padding:.75rem;margin-top:.75rem;line-height:1.8}
.app-inline-notice{position:sticky;top:76px;z-index:1020}
@media(max-width:767.98px){
  .unified-basalam-grid{grid-template-columns:1fr}
  .unified-product-tabs{margin-left:-.25rem;margin-right:-.25rem;padding-left:.25rem;padding-right:.25rem}
  .unified-basalam-actions{display:grid;grid-template-columns:1fr 1fr}
  .unified-basalam-actions .btn{width:100%}
}

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

<div class="unified-product-tabs mb-2" id="unifiedProductTabs" aria-label="نمای محصولات">
            <button type="button" class="unified-product-tab active" data-scope="site">سایت <span class="count"><?= (int)$total ?></span></button>
            <button type="button" class="unified-product-tab" data-scope="linked">متصل به باسلام <span class="count" data-stat="linked">…</span></button>
            <button type="button" class="unified-product-tab" data-scope="candidate">ادغام‌نشده <span class="count" data-stat="candidate">…</span></button>
            <button type="button" class="unified-product-tab" data-scope="basalam_only">فقط باسلام <span class="count" data-stat="basalam_only">…</span></button>
            <button type="button" class="unified-product-tab" data-scope="rejected">ردشده <span class="count" data-stat="rejected">…</span></button>
          </div>
          <div id="appInlineNotice" class="app-inline-notice"></div>

<div id="wooProductsView">
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
</div>

<div id="unifiedBasalamPanel" class="d-none">
  <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h5 class="mb-1" id="unifiedPanelTitle">باسلام</h5>
      <div class="small text-muted" id="unifiedPanelSubtitle"></div>
    </div>
    <?php if (Auth::isAdmin()): ?><a href="basalam.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-gear"></i> تنظیمات</a><?php endif; ?>
  </div>
  <div id="unifiedBasalamContent"></div>
</div>

<script>
const appNotice = document.getElementById('appInlineNotice');
function showAppNotice(message, ok = true) {
  if (!appNotice) return;
  appNotice.innerHTML = '';
  const box = document.createElement('div');
  box.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' alert-dismissible fade show shadow-sm';
  box.setAttribute('role', 'status');
  box.textContent = message;
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'btn-close';
  close.setAttribute('data-bs-dismiss', 'alert');
  box.appendChild(close);
  appNotice.appendChild(box);
}

function escapeAppHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));
}

const scopeTitles = {
  linked: ['محصولات متصل به باسلام', 'این محصولات به یک محصول Woo متصل هستند.'],
  candidate: ['در سایت هست؛ ادغام نشده', 'تطبیق مطمئن بر اساس SKU یا عنوان دقیق پیدا شده ولی هنوز اتصال ثبت نشده است.'],
  basalam_only: ['فقط در باسلام', 'در سایت معادل مطمئنی برای این محصولات پیدا نشده است.'],
  rejected: ['محصولات ردشده باسلام', 'دلیل رد، اصلاح تصویر و بروزرسانی از همین صفحه قابل انجام است.']
};

async function loadUnifiedBasalam(scope, silent = false) {
  const panel = document.getElementById('unifiedBasalamPanel');
  const content = document.getElementById('unifiedBasalamContent');
  const woo = document.getElementById('wooProductsView');
  const title = document.getElementById('unifiedPanelTitle');
  const subtitle = document.getElementById('unifiedPanelSubtitle');
  if (!panel || !content || !woo) return;

  if (scope === 'site') {
    panel.classList.add('d-none');
    woo.classList.remove('d-none');
    return;
  }

  woo.classList.add('d-none');
  panel.classList.remove('d-none');
  const copy = scopeTitles[scope] || ['باسلام', ''];
  title.textContent = copy[0];
  subtitle.textContent = copy[1];
  if (!silent) content.innerHTML = '<div class="card border-0 shadow-sm"><div class="card-body py-5 text-center text-muted"><span class="spinner-border spinner-border-sm ms-2"></span>در حال خواندن باسلام…</div></div>';

  const fd = new FormData();
  fd.append('scope', scope);
  const response = await fetch('ajax/basalam_unified_catalog.php', {method:'POST', body:fd});
  const data = await response.json();
  if (!response.ok || !data.success) throw new Error(data.message || 'خواندن باسلام ناموفق بود.');
  updateUnifiedStats(data.stats || {});
  renderUnifiedBasalam(scope, data.products || []);
}

function updateUnifiedStats(stats) {
  document.querySelectorAll('[data-stat]').forEach(el => {
    const key = el.dataset.stat;
    if (Object.prototype.hasOwnProperty.call(stats, key)) el.textContent = stats[key];
  });
}

function marketBadgeClass(key) {
  return ({available:'success',pending:'warning',rejected:'danger',unpublished:'secondary',inactive:'dark'})[key] || 'secondary';
}

function relationBadgeClass(key) {
  return ({linked:'info',candidate:'warning',basalam_only:'secondary'})[key] || 'secondary';
}

function renderUnifiedBasalam(scope, items) {
  const content = document.getElementById('unifiedBasalamContent');
  if (!items.length) {
    content.innerHTML = '<div class="card border-0 shadow-sm"><div class="card-body py-5 text-center text-muted">موردی در این بخش وجود ندارد.</div></div>';
    return;
  }
  let html = '<div class="unified-basalam-grid">';
  items.forEach(item => {
    const market = item.market || {};
    const relation = item.relation || {};
    const photo = item.photo ? '<img class="unified-basalam-thumb" src="' + escapeAppHtml(item.photo) + '" alt="" loading="lazy">' : '<div class="unified-basalam-thumb d-flex align-items-center justify-content-center text-muted">—</div>';
    const wooId = Number(relation.woo_id || 0);
    html += '<article class="unified-basalam-card" id="unified-card-' + item.basalam_id + '">';
    html += '<div class="unified-basalam-head">' + photo + '<div class="flex-grow-1 min-w-0"><div class="unified-basalam-title">' + escapeAppHtml(item.name) + '</div><div class="small text-muted">Basalam #' + item.basalam_id + '</div><div class="d-flex gap-1 flex-wrap mt-2"><span class="badge text-bg-' + marketBadgeClass(market.key) + '">' + escapeAppHtml(market.label || market.key) + '</span><span class="badge text-bg-' + relationBadgeClass(relation.key) + '">' + escapeAppHtml(relation.label || '') + '</span></div></div></div>';
    html += '<div class="unified-basalam-meta"><div><small>SKU</small><strong dir="ltr">' + escapeAppHtml(item.sku || '-') + '</strong></div><div><small>ارتباط سایت</small><strong>' + (wooId ? 'Woo #' + wooId : 'ندارد') + '</strong></div><div><small>قابل نمایش</small><strong>' + (item.showable === null ? '—' : (item.showable ? 'بله' : 'خیر')) + '</strong></div><div><small>قابل خرید</small><strong>' + (item.available === null ? '—' : (item.available ? 'بله' : 'خیر')) + '</strong></div></div>';
    html += '<div class="unified-basalam-actions">';
    html += '<a class="btn btn-outline-secondary btn-sm" href="https://basalam.com/p/' + item.basalam_id + '" target="_blank" rel="noopener noreferrer"><i class="fas fa-arrow-up-right-from-square"></i> باسلام</a>';
    if (wooId) html += '<a class="btn btn-outline-primary btn-sm" href="product_edit.php?id=' + wooId + '"><i class="fas fa-pen"></i> ویرایش سایت</a>';
    if (relation.key === 'linked' && wooId) html += '<button type="button" class="btn btn-outline-success btn-sm unified-sync-btn" data-wc-id="' + wooId + '"><i class="fas fa-rotate"></i> بروزرسانی باسلام</button>';
    if (relation.key === 'candidate' && wooId) html += '<button type="button" class="btn btn-warning btn-sm unified-link-btn" data-wc-id="' + wooId + '" data-basalam-id="' + item.basalam_id + '"><i class="fas fa-link"></i> اتصال به Woo #' + wooId + '</button>';
    if (market.key === 'rejected') html += '<button type="button" class="btn btn-outline-warning btn-sm unified-reason-btn" data-basalam-id="' + item.basalam_id + '"><i class="fas fa-circle-info"></i> دلیل رد</button>';
    if (market.key === 'rejected' && relation.key === 'linked' && wooId) html += '<button type="button" class="btn btn-outline-danger btn-sm unified-fix-image-btn" data-wc-id="' + wooId + '"><i class="fas fa-crop-simple"></i> اصلاح عکس</button>';
    html += '</div><div class="unified-detail-box d-none" id="unified-detail-' + item.basalam_id + '"></div></article>';
  });
  html += '</div>';
  content.innerHTML = html;
  bindUnifiedActions(scope);
}

function bindUnifiedActions(scope) {
  document.querySelectorAll('.unified-sync-btn').forEach(btn => btn.addEventListener('click', async function(){
    const old = this.innerHTML; this.disabled = true; this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const fd = new FormData(); fd.append('id', this.dataset.wcId); fd.append('force','1');
      const r = await fetch('ajax/basalam_sync_product.php',{method:'POST',body:fd}); const d = await r.json();
      if (!r.ok || !d.success) throw new Error(d.message || 'بروزرسانی ناموفق بود.');
      showAppNotice(d.message || 'بروزرسانی باسلام انجام شد.', true);
    } catch(e) { showAppNotice(e.message || 'بروزرسانی ناموفق بود.', false); }
    finally { this.disabled=false; this.innerHTML=old; }
  }));

  document.querySelectorAll('.unified-link-btn').forEach(btn => btn.addEventListener('click', async function(){
    if (!confirm('این محصول باسلام به Woo #' + this.dataset.wcId + ' متصل شود؟')) return;
    const old = this.innerHTML; this.disabled = true; this.textContent = 'در حال اتصال…';
    try {
      const fd = new FormData(); fd.append('wc_product_id',this.dataset.wcId); fd.append('basalam_product_id',this.dataset.basalamId);
      const r = await fetch('ajax/basalam_link_candidate.php',{method:'POST',body:fd}); const d = await r.json();
      if (!r.ok || !d.success) throw new Error(d.message || 'اتصال ناموفق بود.');
      showAppNotice(d.message || 'اتصال انجام شد.', true);
      await loadUnifiedBasalam(scope, true);
    } catch(e) { showAppNotice(e.message || 'اتصال ناموفق بود.', false); this.disabled=false; this.innerHTML=old; }
  }));

  document.querySelectorAll('.unified-reason-btn').forEach(btn => btn.addEventListener('click', async function(){
    const box = document.getElementById('unified-detail-' + this.dataset.basalamId);
    if (!box) return;
    if (box.dataset.loaded === '1') { box.classList.toggle('d-none'); return; }
    const old = this.innerHTML; this.disabled=true; this.textContent='در حال خواندن…';
    try {
      const r = await fetch('ajax/basalam_product_status_detail.php?id=' + encodeURIComponent(this.dataset.basalamId)); const d = await r.json();
      if (!r.ok || !d.success) throw new Error(d.message || 'دلیل رد دریافت نشد.');
      const reasons = Array.isArray(d.reasons) ? d.reasons : [];
      let h = d.message ? '<div class="mb-2">' + escapeAppHtml(d.message).replace(/\n/g,'<br>') + '</div>' : '';
      if (reasons.length) h += '<strong>دلیل باسلام:</strong><ul class="mb-0 mt-1">' + reasons.map(x => '<li>' + escapeAppHtml(x) + '</li>').join('') + '</ul>';
      if (!h) h = '<span class="text-muted">جزئیات بیشتری از API برنگشت.</span>';
      box.innerHTML=h; box.dataset.loaded='1'; box.classList.remove('d-none');
    } catch(e) { showAppNotice(e.message || 'دلیل رد دریافت نشد.', false); }
    finally { this.disabled=false; this.innerHTML=old; }
  }));

  document.querySelectorAll('.unified-fix-image-btn').forEach(btn => btn.addEventListener('click', async function(){
    if (!confirm('نسخه مخصوص باسلام از تصاویر این محصول ساخته و دوباره ارسال شود؟ عکس‌های سایت تغییر نمی‌کنند.')) return;
    const old=this.innerHTML; this.disabled=true; this.textContent='در حال اصلاح…';
    try {
      const fd=new FormData(); fd.append('wc_product_id',this.dataset.wcId); fd.append('crop_top_percent','36');
      const r=await fetch('ajax/basalam_prepare_safe_images.php',{method:'POST',body:fd}); const d=await r.json();
      if (!r.ok || !d.success) throw new Error(d.message || 'اصلاح تصویر ناموفق بود.');
      showAppNotice(d.message || 'تصاویر مخصوص باسلام آماده و ارسال شدند.', true);
    } catch(e) { showAppNotice(e.message || 'اصلاح تصویر ناموفق بود.', false); }
    finally { this.disabled=false; this.innerHTML=old; }
  }));
}

document.querySelectorAll('.unified-product-tab').forEach(tab => {
  tab.addEventListener('click', async function(){
    document.querySelectorAll('.unified-product-tab').forEach(x => x.classList.remove('active'));
    this.classList.add('active');
    const scope=this.dataset.scope || 'site';
    try { await loadUnifiedBasalam(scope); } catch(e) { showAppNotice(e.message || 'بارگذاری ناموفق بود.', false); }
  });
});

(async () => {
  try {
    const fd=new FormData(); fd.append('scope','stats');
    const r=await fetch('ajax/basalam_unified_catalog.php',{method:'POST',body:fd}); const d=await r.json();
    if (r.ok && d.success) updateUnifiedStats(d.stats || {});
  } catch(e) {}
})();

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
      showAppNotice((data.message || 'بروزرسانی باسلام انجام شد.') + warningText, true);
    } catch (error) {
      showAppNotice(error.message || 'بروزرسانی باسلام ناموفق بود.', false);
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
