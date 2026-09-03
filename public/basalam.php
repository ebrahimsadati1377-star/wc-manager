<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$sync = new BasalamSync();
$wc = new WooCommerceClient();
$basalam = new BasalamClient();

function flattenBasalamCategories(array $categories, int $depth = 0, array &$out = []): array
{
    foreach ($categories as $category) {
        if (!is_array($category)) continue;
        $id = (int)($category['id'] ?? 0);
        $title = trim((string)($category['title'] ?? ''));
        if ($id > 0) {
            $out[] = ['id' => $id, 'title' => $title !== '' ? $title : ('#' . $id), 'depth' => $depth];
        }
        if (!empty($category['children']) && is_array($category['children'])) {
            flattenBasalamCategories($category['children'], $depth + 1, $out);
        }
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        setFlash('danger', 'نشست منقضی شده، دوباره تلاش کنید.');
        redirect('basalam.php');
    }

    $action = (string)($_POST['action'] ?? 'save_settings');

    if ($action === 'save_settings') {
        $authMode = in_array(($_POST['basalam_auth_mode'] ?? ''), ['personal_token', 'client_credentials'], true)
            ? (string)$_POST['basalam_auth_mode'] : 'personal_token';
        $vendorId = max(0, (int)($_POST['basalam_vendor_id'] ?? 0));
        $accessToken = trim((string)($_POST['basalam_access_token'] ?? ''));
        $clientId = trim((string)($_POST['basalam_client_id'] ?? ''));
        $clientSecret = trim((string)($_POST['basalam_client_secret'] ?? ''));
        $scopes = trim((string)($_POST['basalam_scopes'] ?? 'vendor.product.read vendor.product.write'));

        if ($accessToken === '') $accessToken = (string)getSetting('basalam_access_token', '');
        if ($clientSecret === '') $clientSecret = (string)getSetting('basalam_client_secret', '');

        setSetting('basalam_auth_mode', $authMode);
        setSetting('basalam_vendor_id', (string)$vendorId);
        setSetting('basalam_access_token', $accessToken);
        setSetting('basalam_client_id', $clientId);
        setSetting('basalam_client_secret', $clientSecret);
        setSetting('basalam_scopes', $scopes ?: 'vendor.product.read vendor.product.write');
        setSetting('basalam_price_multiplier', (string)max(0.0001, (float)($_POST['basalam_price_multiplier'] ?? 1)));
        setSetting('basalam_weight_multiplier', (string)max(0.0001, (float)($_POST['basalam_weight_multiplier'] ?? 1000)));
        setSetting('basalam_preparation_days', (string)max(1, (int)($_POST['basalam_preparation_days'] ?? 1)));
        setSetting('basalam_default_package_weight', (string)max(0, (int)($_POST['basalam_default_package_weight'] ?? 0)));
        setSetting('basalam_unmanaged_stock', (string)max(1, (int)($_POST['basalam_unmanaged_stock'] ?? 1)));
        setSetting('basalam_max_images', (string)min(10, max(1, (int)($_POST['basalam_max_images'] ?? 6))));
        setSetting('basalam_sync_images', !empty($_POST['basalam_sync_images']) ? '1' : '0');

        logActivity('update_basalam_settings', 'basalam', 'تنظیمات اتصال و سینک باسلام به‌روزرسانی شد.');
        setFlash('success', 'تنظیمات باسلام ذخیره شد.');
        redirect('basalam.php');
    }

    if ($action === 'save_category_maps') {
        $maps = [];
        $posted = $_POST['category_map'] ?? [];
        $labels = $_POST['category_label'] ?? [];
        if (is_array($posted)) {
            foreach ($posted as $wcCategoryId => $basalamCategoryId) {
                $wcId = (int)$wcCategoryId;
                $basalamId = (int)$basalamCategoryId;
                if ($wcId <= 0 || $basalamId <= 0) continue;
                $maps[] = [
                    'wc_category_id' => $wcId,
                    'basalam_category_id' => $basalamId,
                    'basalam_category_name' => is_array($labels) ? (string)($labels[$wcId] ?? '') : '',
                ];
            }
        }
        $sync->replaceCategoryMaps($maps);
        logActivity('update_basalam_category_maps', 'basalam', 'تعداد نگاشت‌های ذخیره‌شده: ' . count($maps));
        setFlash('success', 'نگاشت دسته‌بندی‌های ووکامرس و باسلام ذخیره شد.');
        redirect('basalam.php#category-mapping');
    }
}

$wcCategories = [];
$wcError = null;
if ($wc->isConfigured()) {
    $res = $wc->getCategories(['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
    if ($res['error']) $wcError = $res['error']; else $wcCategories = $res['body'];
}

$basalamCategories = [];
$basalamError = null;
if ($basalam->isConfigured()) {
    $res = $basalam->getCategories();
    if ($res['error']) {
        $basalamError = $res['error'];
    } else {
        $rootCategories = $res['body']['data'] ?? $res['body'];
        if (is_array($rootCategories)) {
            $flat = [];
            $basalamCategories = flattenBasalamCategories($rootCategories, 0, $flat);
        }
    }
}

$categoryMaps = $sync->getCategoryMaps();
$pageTitle = 'اتصال باسلام';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h3 class="mb-1">اتصال و سینک باسلام</h3>
    <div class="text-muted small">WooCommerce مرجع اصلی محصول، قیمت و موجودی است.</div>
  </div>
  <span class="badge text-bg-<?= $basalam->isConfigured() ? 'success' : 'secondary' ?> px-3 py-2">
    <?= $basalam->isConfigured() ? 'تنظیم‌شده' : 'تنظیم‌نشده' ?>
  </span>
</div>

<div class="row g-4">
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header fw-bold">تنظیمات اتصال</div>
      <div class="card-body">
        <form method="post" id="basalamSettingsForm">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="save_settings">

          <div class="mb-3">
            <label class="form-label">شناسه غرفه (Vendor ID)</label>
            <input type="number" min="1" name="basalam_vendor_id" class="form-control" dir="ltr"
                   value="<?= e(getSetting('basalam_vendor_id', '')) ?>" placeholder="مثلاً 123456">
          </div>

          <div class="mb-3">
            <label class="form-label">روش احراز هویت</label>
            <?php $authMode = (string)getSetting('basalam_auth_mode', 'personal_token'); ?>
            <select name="basalam_auth_mode" id="basalamAuthMode" class="form-select">
              <option value="personal_token" <?= $authMode === 'personal_token' ? 'selected' : '' ?>>Personal Access Token</option>
              <option value="client_credentials" <?= $authMode === 'client_credentials' ? 'selected' : '' ?>>Client Credentials (پیشنهادی برای سرور)</option>
            </select>
          </div>

          <div id="personalTokenFields" class="border rounded p-3 mb-3 bg-light">
            <label class="form-label">Access Token</label>
            <input type="password" name="basalam_access_token" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ توکن فعلی خالی بگذارید">
            <div class="form-text">توکن ذخیره‌شده دوباره در HTML نمایش داده نمی‌شود.</div>
          </div>

          <div id="clientCredentialFields" class="border rounded p-3 mb-3 bg-light">
            <div class="mb-3">
              <label class="form-label">Client ID</label>
              <input type="text" name="basalam_client_id" class="form-control" dir="ltr" value="<?= e(getSetting('basalam_client_id', '')) ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Client Secret</label>
              <input type="password" name="basalam_client_secret" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ Secret فعلی خالی بگذارید">
            </div>
            <div>
              <label class="form-label">Scopes</label>
              <input type="text" name="basalam_scopes" class="form-control" dir="ltr" value="<?= e(getSetting('basalam_scopes', 'vendor.product.read vendor.product.write')) ?>">
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">ضریب قیمت Woo → باسلام</label>
              <input type="number" step="0.0001" min="0.0001" name="basalam_price_multiplier" class="form-control" dir="ltr" value="<?= e(getSetting('basalam_price_multiplier', '1')) ?>">
              <div class="form-text">اگر واحد دو سیستم یکسان است: 1. تبدیل تومان به ریال: 10.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">ضریب وزن Woo → باسلام</label>
              <input type="number" step="0.0001" min="0.0001" name="basalam_weight_multiplier" class="form-control" dir="ltr" value="<?= e(getSetting('basalam_weight_multiplier', '1000')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">زمان آماده‌سازی (روز)</label>
              <input type="number" min="1" name="basalam_preparation_days" class="form-control" value="<?= e(getSetting('basalam_preparation_days', '1')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">وزن بسته‌بندی پیش‌فرض</label>
              <input type="number" min="0" name="basalam_default_package_weight" class="form-control" value="<?= e(getSetting('basalam_default_package_weight', '0')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">موجودی محصول بدون Manage Stock</label>
              <input type="number" min="1" name="basalam_unmanaged_stock" class="form-control" value="<?= e(getSetting('basalam_unmanaged_stock', '1')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">حداکثر تصاویر هر محصول</label>
              <input type="number" min="1" max="10" name="basalam_max_images" class="form-control" value="<?= e(getSetting('basalam_max_images', '6')) ?>">
            </div>
          </div>

          <div class="form-check form-switch my-3">
            <input class="form-check-input" type="checkbox" name="basalam_sync_images" value="1" id="basalamSyncImages" <?= (string)getSetting('basalam_sync_images', '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="basalamSyncImages">آپلود تصاویر Woo روی باسلام هنگام ساخت محصول</label>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" type="submit">ذخیره تنظیمات</button>
            <button class="btn btn-outline-secondary" type="button" id="testBasalamBtn">تست اتصال</button>
            <span id="testBasalamResult" class="align-self-center small"></span>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header fw-bold">وضعیت راه‌اندازی</div>
      <div class="card-body">
        <div class="list-group list-group-flush">
          <div class="list-group-item d-flex justify-content-between"><span>اتصال WooCommerce</span><strong class="<?= $wc->isConfigured() ? 'text-success' : 'text-danger' ?>"><?= $wc->isConfigured() ? 'آماده' : 'تنظیم نشده' ?></strong></div>
          <div class="list-group-item d-flex justify-content-between"><span>اتصال باسلام</span><strong class="<?= $basalam->isConfigured() ? 'text-success' : 'text-warning' ?>"><?= $basalam->isConfigured() ? 'تنظیم شده' : 'نیاز به تنظیم' ?></strong></div>
          <div class="list-group-item d-flex justify-content-between"><span>نگاشت دسته‌بندی</span><strong><?= count($categoryMaps) ?> مورد</strong></div>
        </div>
        <div class="alert alert-info mt-3 mb-0 small">تا وقتی دسته Woo به دسته باسلام نگاشت نشده باشد، محصول در باسلام ساخته نمی‌شود.</div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4" id="category-mapping">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <strong>نگاشت دسته‌بندی WooCommerce → باسلام</strong>
    <span class="text-muted small"><?= count($wcCategories) ?> دسته Woo / <?= count($basalamCategories) ?> دسته باسلام</span>
  </div>
  <div class="card-body">
    <?php if ($wcError): ?>
      <div class="alert alert-danger">خطای WooCommerce: <?= e($wcError) ?></div>
    <?php elseif ($basalamError): ?>
      <div class="alert alert-danger">خطای باسلام: <?= e($basalamError) ?></div>
    <?php elseif (!$basalam->isConfigured()): ?>
      <div class="alert alert-warning mb-0">ابتدا تنظیمات اتصال باسلام را ذخیره کنید.</div>
    <?php elseif (empty($basalamCategories)): ?>
      <div class="alert alert-warning mb-0">دسته‌بندی‌ای از باسلام دریافت نشد.</div>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_category_maps">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>دسته WooCommerce</th><th>دسته باسلام</th></tr></thead>
            <tbody>
              <?php foreach ($wcCategories as $wcCategory): ?>
                <?php $wcId = (int)($wcCategory['id'] ?? 0); $mappedId = (int)($categoryMaps[$wcId]['basalam_category_id'] ?? 0); ?>
                <tr>
                  <td><strong><?= e($wcCategory['name'] ?? ('#' . $wcId)) ?></strong><div class="text-muted small">Woo #<?= $wcId ?></div></td>
                  <td>
                    <select name="category_map[<?= $wcId ?>]" class="form-select form-select-sm category-map-select" data-wc-id="<?= $wcId ?>">
                      <option value="0">— بدون نگاشت —</option>
                      <?php foreach ($basalamCategories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" data-title="<?= e($category['title']) ?>" <?= $mappedId === (int)$category['id'] ? 'selected' : '' ?>><?= e(str_repeat('— ', (int)$category['depth']) . $category['title']) ?> (#<?= (int)$category['id'] ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="category_label[<?= $wcId ?>]" id="category-label-<?= $wcId ?>" value="<?= e($categoryMaps[$wcId]['basalam_category_name'] ?? '') ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button class="btn btn-success" type="submit">ذخیره نگاشت دسته‌ها</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  const authMode = document.getElementById('basalamAuthMode');
  const personal = document.getElementById('personalTokenFields');
  const client = document.getElementById('clientCredentialFields');
  function syncAuthFields() {
    const isClient = authMode.value === 'client_credentials';
    personal.style.display = isClient ? 'none' : '';
    client.style.display = isClient ? '' : 'none';
  }
  authMode.addEventListener('change', syncAuthFields); syncAuthFields();

  document.getElementById('testBasalamBtn').addEventListener('click', function () {
    const btn = this; const result = document.getElementById('testBasalamResult');
    btn.disabled = true; result.className = 'align-self-center small text-muted'; result.textContent = 'در حال تست...';
    fetch('ajax/basalam_test.php', { method: 'POST' })
      .then(r => r.json()).then(data => {
        result.className = 'align-self-center small ' + (data.success ? 'text-success' : 'text-danger');
        result.textContent = data.message || (data.success ? 'اتصال برقرار است.' : 'اتصال ناموفق است.');
      }).catch(() => { result.className = 'align-self-center small text-danger'; result.textContent = 'خطا در ارتباط با سرور.'; })
      .finally(() => { btn.disabled = false; });
  });

  document.querySelectorAll('.category-map-select').forEach(select => {
    function updateLabel() {
      const wcId = select.dataset.wcId; const option = select.options[select.selectedIndex];
      const target = document.getElementById('category-label-' + wcId);
      if (target) target.value = option?.dataset?.title || '';
    }
    select.addEventListener('change', updateLabel); updateLabel();
  });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
