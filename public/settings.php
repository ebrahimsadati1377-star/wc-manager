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

    if ($ck === '') $ck = (string)getSetting('consumer_key');
    if ($cs === '') $cs = (string)getSetting('consumer_secret');
    if ($wpAppPass === '') $wpAppPass = (string)getSetting('wp_app_password');

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
          <label class="form-label">WooCommerce Consumer Key</label>
          <input type="password" name="consumer_key" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ کلید فعلی خالی بگذارید">
          <div class="form-text">کلید ذخیره‌شده نمایش داده نمی‌شود.</div>
        </div>

        <div class="col-md-6 mb-3">
          <label class="form-label">WooCommerce Consumer Secret</label>
          <input type="password" name="consumer_secret" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ Secret فعلی خالی بگذارید">
          <div class="form-text">Secret ذخیره‌شده نمایش داده نمی‌شود.</div>
        </div>
      </div>

      <div class="p-3 bg-light rounded border mb-4">
        <div class="fw-bold mb-2 text-primary">🔐 احراز هویت وردپرس برای رسانه</div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">نام کاربری وردپرس</label>
            <input type="text" name="wp_username" class="form-control" dir="ltr" autocomplete="username" placeholder="مثلا: admin" value="<?= e(getSetting('wp_username')) ?>">
          </div>

          <div class="col-md-6 mb-3">
            <label class="form-label">Application Password</label>
            <input type="password" name="wp_app_password" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ رمز فعلی خالی بگذارید">
            <div class="form-text">رمز ذخیره‌شده دوباره در HTML نمایش داده نمی‌شود.</div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
      <button type="button" class="btn btn-outline-secondary" id="testConnBtn">تست اتصال</button>
      <span id="testConnResult" class="ms-2"></span>
    </form>

    <hr>
    <div class="text-muted small">
      <p class="mb-1"><strong>راهنمای کلیدهای ووکامرس:</strong> در پیشخوان وردپرس به مسیر <code>WooCommerce &rarr; تنظیمات &rarr; پیشرفته &rarr; REST API</code> بروید و یک کلید جدید با دسترسی «خواندن/نوشتن» بسازید.</p>
      <p class="mb-0"><strong>راهنمای رمز اپلیکیشن:</strong> در پیشخوان وردپرس به مسیر <code>کاربران &rarr; شناسنامه شما</code> رفته و در بخش «رمزهای عبور اپلیکیشن» یک رمز جدید تولید کنید.</p>
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
      resultEl.textContent = data.message || (data.success ? 'اتصال برقرار است.' : 'اتصال ناموفق بود.');
      resultEl.className = data.success ? 'ms-2 text-success' : 'ms-2 text-danger';
    })
    .catch(() => {
      resultEl.textContent = 'خطا در برقراری ارتباط';
      resultEl.className = 'ms-2 text-danger';
    })
    .finally(() => { btn.disabled = false; });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
