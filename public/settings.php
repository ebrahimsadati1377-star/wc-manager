<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        setFlash('danger', 'نشست منقضی شده، دوباره تلاش کنید.');
        redirect('settings.php');
    }
    $storeUrl   = rtrim(trim($_POST['store_url'] ?? ''), '/');
    $ck         = trim($_POST['consumer_key'] ?? '');
    $cs         = trim($_POST['consumer_secret'] ?? '');
    $wpUser     = trim($_POST['wp_username'] ?? '');
    $wpAppPass  = trim($_POST['wp_app_password'] ?? '');
    $siteTitle  = trim($_POST['site_title'] ?? '');

    setSetting('store_url', $storeUrl);
    setSetting('consumer_key', $ck);
    setSetting('consumer_secret', $cs);
    setSetting('wp_username', $wpUser);
    setSetting('wp_app_password', $wpAppPass);
    setSetting('site_title', $siteTitle ?: APP_NAME);
    
    logActivity('update_settings', 'settings', 'به‌روزرسانی تنظیمات اتصال ووکامرس و وردپرس');
    setFlash('success', 'تنظیمات ذخیره شد.');
    redirect('settings.php');
}

$pageTitle = 'تنظیمات اتصال';
require __DIR__ . '/partials/header.php';
?>

<h3 class="mb-4">تنظیمات اتصال به ووکامرس و وردپرس</h3>

<div class="card">
  <div class="card-body">
    <form method="post" id="settingsForm">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="mb-3">
        <label class="form-label">عنوان سایت مدیریت</label>
        <input type="text" name="site_title" class="form-control" value="<?= e(getSetting('site_title')) ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">آدرس فروشگاه ووکامرس <span class="text-danger">*</span></label>
        <input type="url" name="store_url" class="form-control" placeholder="https://example.com" value="<?= e(getSetting('store_url')) ?>" required>
        <div class="form-text">بدون اسلش انتهایی، مثال: https://mystore.com</div>
      </div>

      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">WooCommerce Consumer Key <span class="text-danger">*</span></label>
          <input type="text" name="consumer_key" class="form-control" dir="ltr" value="<?= e(getSetting('consumer_key')) ?>" required>
        </div>

        <div class="col-md-6 mb-3">
          <label class="form-label">WooCommerce Consumer Secret <span class="text-danger">*</span></label>
          <input type="text" name="consumer_secret" class="form-control" dir="ltr" value="<?= e(getSetting('consumer_secret')) ?>" required>
        </div>
      </div>

      <div class="p-3 bg-light rounded border mb-4">
        <div class="fw-bold mb-2 text-primary">🔐 احراز هویت پیشرفته وردپرس (جهت رفع خطای آپلود عکس)</div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">نام کاربری وردپرس (ادمین)</label>
            <input type="text" name="wp_username" class="form-control" dir="ltr" placeholder="مثلا: admin" value="<?= e(getSetting('wp_username')) ?>">
          </div>

          <div class="col-md-6 mb-3">
            <label class="form-label">رمز عبور اپلیکیشن (Application Password)</label>
            <input type="text" name="wp_app_password" class="form-control" dir="ltr" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" value="<?= e(getSetting('wp_app_password')) ?>">
          </div>
        </div>
        <div class="form-text text-muted">نکته: در صورت پر کردن این دو فیلد، سیستم برای آپلود تصاویر متغیرها از این لایه امنیتی استفاده خواهد کرد.</div>
      </div>

      <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
      <button type="button" class="btn btn-outline-secondary" id="testConnBtn">تست اتصال</button>
      <span id="testConnResult" class="ms-2"></span>
    </form>

    <hr>
    <div class="text-muted small">
      <p class="mb-1"><strong>راهنمای کلیدهای ووکامرس:</strong> در پیشخوان وردپرس به مسیر <code>WooCommerce &rarr; تنظیمات &rarr; پیشرفته &rarr; REST API</code> بروید و یک کلید جدید با دسترسی «خواندن/نوشتن» بسازید.</p>
      <p class="mb-0"><strong>راهنمای رمز اپلیکیشن:</strong> در پیشخوان وردپرس به مسیر <code>کاربران &rarr; شناسنامه شما</code> رفته و در بخش «رمزهای عبور اپلیکیشن» یک رمز جدید تولید و بدون فاصله در کادر بالا وارد کنید.</p>
    </div>
  </div>
</div>

<script>
document.getElementById('testConnBtn').addEventListener('click', function () {
  const btn = this;
  const resultEl = document.getElementById('testConnResult');
  const form = document.getElementById('settingsForm');
  const fd = new FormData(form);
  btn.disabled = true;
  resultEl.textContent = 'در حال بررسی...';
  fetch('ajax/settings_test.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      resultEl.innerHTML = data.success
        ? '<span class="text-success">✅ ' + data.message + '</span>'
        : '<span class="text-danger">❌ ' + data.message + '</span>';
    })
    .catch(() => { resultEl.innerHTML = '<span class="text-danger">خطا در برقراری ارتباط</span>'; })
    .finally(() => { btn.disabled = false; });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>