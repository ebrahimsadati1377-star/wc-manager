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

$wc = new WooCommerceClient();
$isConfigured = $wc->isConfigured();
$pageTitle = 'تنظیمات اتصال';
require __DIR__ . '/partials/header.php';
?>

<style>
.settings-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:1rem;align-items:start}.settings-sidebar{position:sticky;top:92px}.settings-status{display:flex;align-items:center;gap:.65rem;padding:.85rem;border-radius:14px;background:#f8fafc;border:1px solid #e8edf3}.settings-status__icon{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:#ecfdf3;color:#15803d}.settings-help-list{display:grid;gap:.7rem}.settings-help-item{display:flex;gap:.65rem;align-items:flex-start}.settings-help-item i{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;background:#f1f5f9;color:#64748b;flex:0 0 30px}.settings-help-item strong{display:block;font-size:.78rem}.settings-help-item span{display:block;color:#7c8797;font-size:.7rem;line-height:1.7;margin-top:.15rem}.settings-savebar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding-top:.25rem}@media(max-width:991.98px){.settings-layout{grid-template-columns:1fr}.settings-sidebar{position:static}}@media(max-width:767.98px){.settings-savebar{display:grid;grid-template-columns:1fr}.settings-savebar .btn{width:100%}.settings-savebar #testConnResult{margin:0!important;text-align:center}}
</style>

<div class="app-page-head">
  <div class="app-page-head__copy">
    <div class="app-page-head__eyebrow"><i class="fas fa-plug"></i> اتصال فروشگاه</div>
    <h1 class="app-page-head__title">تنظیمات WooCommerce و WordPress</h1>
    <p class="app-page-head__subtitle">کلیدهای اتصال، رسانه وردپرس و عنوان پنل را از یک محل امن مدیریت کنید.</p>
  </div>
  <div class="app-page-head__actions">
    <span class="app-meta-chip"><span class="app-status-dot <?= $isConfigured ? 'success' : 'warning' ?>"></span><?= $isConfigured ? 'اتصال تنظیم شده' : 'نیاز به تنظیم' ?></span>
  </div>
</div>

<form method="post" id="settingsForm">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <div class="settings-layout">
    <div class="d-grid gap-3">
      <section class="app-section-card">
        <div class="app-section-card__head"><div><h2>تنظیمات عمومی</h2><p>نام پنل و آدرس اصلی فروشگاه.</p></div><i class="fas fa-store text-primary"></i></div>
        <div class="app-section-card__body">
          <div class="mb-3"><label class="form-label">عنوان سایت مدیریت</label><input type="text" name="site_title" class="form-control" value="<?= e(getSetting('site_title')) ?>" placeholder="WC Manager"></div>
          <div><label class="form-label">آدرس فروشگاه ووکامرس <span class="text-danger">*</span></label><input type="url" name="store_url" class="form-control" placeholder="https://example.com" value="<?= e(getSetting('store_url')) ?>" required><div class="form-text">آدرس اصلی سایت بدون اسلش انتهایی وارد شود.</div></div>
        </div>
      </section>

      <section class="app-section-card">
        <div class="app-section-card__head"><div><h2>WooCommerce REST API</h2><p>برای خواندن و ویرایش محصولات به دسترسی Read/Write نیاز است.</p></div><i class="fas fa-key text-primary"></i></div>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Consumer Key</label><input type="password" name="consumer_key" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ کلید فعلی خالی بگذارید"><div class="form-text">کلید ذخیره‌شده هیچ‌وقت داخل HTML نمایش داده نمی‌شود.</div></div>
            <div class="col-md-6"><label class="form-label">Consumer Secret</label><input type="password" name="consumer_secret" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ Secret فعلی خالی بگذارید"><div class="form-text">فقط در صورت تغییر، مقدار جدید وارد کنید.</div></div>
          </div>
        </div>
      </section>

      <section class="app-section-card">
        <div class="app-section-card__head"><div><h2>رسانه WordPress</h2><p>برای آپلود تصویر و رسانه از Application Password استفاده می‌شود.</p></div><i class="fas fa-images text-primary"></i></div>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">نام کاربری وردپرس</label><input type="text" name="wp_username" class="form-control" dir="ltr" autocomplete="username" placeholder="admin" value="<?= e(getSetting('wp_username')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Application Password</label><input type="password" name="wp_app_password" class="form-control" dir="ltr" autocomplete="new-password" placeholder="برای حفظ رمز فعلی خالی بگذارید"><div class="form-text">رمز فعلی از سرور به مرورگر برگردانده نمی‌شود.</div></div>
          </div>
        </div>
      </section>

      <div class="settings-savebar">
        <button type="submit" class="btn btn-primary"><i class="fas fa-check ms-1"></i>ذخیره تنظیمات</button>
        <button type="button" class="btn btn-outline-secondary" id="testConnBtn"><i class="fas fa-signal ms-1"></i>تست اتصال</button>
        <span id="testConnResult" class="ms-2 small"></span>
      </div>
    </div>

    <aside class="settings-sidebar d-grid gap-3">
      <div class="app-section-card"><div class="app-section-card__body"><div class="settings-status"><div class="settings-status__icon"><i class="fas fa-shield-halved"></i></div><div><strong class="d-block small">اطلاعات حساس محافظت می‌شوند</strong><span class="text-muted" style="font-size:.7rem">Secret و Password ذخیره‌شده دوباره نمایش داده نمی‌شوند.</span></div></div></div></div>
      <div class="app-section-card">
        <div class="app-section-card__head"><div><h2>راهنمای سریع</h2></div></div>
        <div class="app-section-card__body settings-help-list">
          <div class="settings-help-item"><i class="fas fa-cart-shopping"></i><div><strong>WooCommerce API</strong><span>WooCommerce ← تنظیمات ← پیشرفته ← REST API و یک کلید Read/Write بسازید.</span></div></div>
          <div class="settings-help-item"><i class="fas fa-lock"></i><div><strong>Application Password</strong><span>در شناسنامه کاربر وردپرس یک Application Password جدا برای این پنل بسازید.</span></div></div>
          <div class="settings-help-item"><i class="fas fa-circle-check"></i><div><strong>بعد از ذخیره</strong><span>دکمه «تست اتصال» را بزنید تا وضعیت ارتباط بدون تغییر اطلاعات بررسی شود.</span></div></div>
        </div>
      </div>
    </aside>
  </div>
</form>

<script>
document.getElementById('testConnBtn').addEventListener('click', function () {
  const btn = this;
  const resultEl = document.getElementById('testConnResult');
  const form = document.getElementById('settingsForm');
  const fd = new FormData(form);
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm ms-1"></span>در حال بررسی';
  resultEl.textContent = '';
  fetch('ajax/settings_test.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      resultEl.textContent = data.message || (data.success ? 'اتصال برقرار است.' : 'اتصال ناموفق بود.');
      resultEl.className = data.success ? 'ms-2 small text-success fw-semibold' : 'ms-2 small text-danger fw-semibold';
    })
    .catch(() => {
      resultEl.textContent = 'خطا در برقراری ارتباط';
      resultEl.className = 'ms-2 small text-danger fw-semibold';
    })
    .finally(() => { btn.disabled = false; btn.innerHTML = original; });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
