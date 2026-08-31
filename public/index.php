<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$wc = new WooCommerceClient();
$configured = $wc->isConfigured();

$productCount = null;
$categoryCount = null;
$connError = null;

if ($configured) {
    $res = $wc->getProducts(['per_page' => 1]);
    if ($res['error']) {
        $connError = $res['error'];
    } else {
        $productCount = $res['headers']['total'];
    }
    $catRes = $wc->getCategories(['per_page' => 1]);
    if (!$catRes['error']) {
        $categoryCount = $catRes['headers']['total'];
    }
}

$pageTitle = 'داشبورد';
require __DIR__ . '/partials/header.php';
?>

<h3 class="mb-4">داشبورد</h3>

<?php if (!$configured): ?>
  <div class="alert alert-warning">
    اتصال به ووکامرس هنوز تنظیم نشده است.
    <?php if (Auth::isAdmin()): ?>
      برای شروع به <a href="settings.php">صفحه تنظیمات</a> بروید و آدرس سایت و Consumer Key/Secret را وارد کنید.
    <?php else: ?>
      لطفاً از مدیر سیستم بخواهید این مورد را تنظیم کند.
    <?php endif; ?>
  </div>
<?php elseif ($connError): ?>
  <div class="alert alert-danger">
    خطا در اتصال به ووکامرس: <?= e($connError) ?>
  </div>
<?php else: ?>
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card stat-card text-bg-primary">
        <div class="card-body">
          <div class="fs-2 fw-bold"><?= $productCount !== null ? e($productCount) : '—' ?></div>
          <div>تعداد کل محصولات</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stat-card text-bg-success">
        <div class="card-body">
          <div class="fs-2 fw-bold"><?= $categoryCount !== null ? e($categoryCount) : '—' ?></div>
          <div>تعداد دسته‌بندی‌ها</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stat-card text-bg-info">
        <div class="card-body">
          <div class="fs-6">✅ اتصال به ووکامرس برقرار است</div>
          <div class="small text-truncate"><?= e(getSetting('store_url')) ?></div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<h5 class="mt-4 mb-3 text-secondary">📦 مدیریت فروشگاه</h5>
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6">
    <a href="product_edit.php" class="text-decoration-none">
      <div class="card action-card">
        <div class="card-body text-center py-4">
          <div class="fs-1">➕</div>
          <div class="fw-bold mt-2">افزودن محصول جدید</div>
        </div>
      </div>
    </a>
  </div>
  
  <div class="col-md-3 col-sm-6">
    <a href="products.php" class="text-decoration-none">
      <div class="card action-card">
        <div class="card-body text-center py-4">
          <div class="fs-1">📦</div>
          <div class="fw-bold mt-2">مدیریت محصولات</div>
        </div>
      </div>
    </a>
  </div>
  
  <div class="col-md-3 col-sm-6">
    <a href="categories.php" class="text-decoration-none">
      <div class="card action-card">
        <div class="card-body text-center py-4">
          <div class="fs-1">🗂️</div>
          <div class="fw-bold mt-2">مدیریت دسته‌بندی‌ها</div>
        </div>
      </div>
    </a>
  </div>
</div>

<h5 class="mt-5 mb-3 text-secondary">📝 مدیریت مجله</h5>
<div class="row g-3">
  <div class="col-md-3 col-sm-6">
    <a href="add-post.php" class="text-decoration-none">
      <div class="card action-card border-primary h-100">
        <div class="card-body text-center py-4">
          <div class="fs-1">✍️</div>
          <div class="fw-bold mt-2 text-primary">افزودن مقاله جدید</div>
        </div>
      </div>
    </a>
  </div>

  <div class="col-md-3 col-sm-6">
    <a href="manage-posts.php" class="text-decoration-none">
      <div class="card action-card border-success h-100">
        <div class="card-body text-center py-4">
          <div class="fs-1">📋</div>
          <div class="fw-bold mt-2 text-success">مدیریت مقالات</div>
        </div>
      </div>
    </a>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>