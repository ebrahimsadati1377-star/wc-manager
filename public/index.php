<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$wc = new WooCommerceClient();
$basalam = new BasalamClient();
$user = Auth::user();

$wooConfigured = $wc->isConfigured();
$wooOnline = false;
$wooError = null;
$productCount = null;
$categoryCount = null;

if ($wooConfigured) {
    $res = $wc->getProducts(['per_page' => 1]);
    if ($res['error']) {
        $wooError = $res['error'];
    } else {
        $wooOnline = true;
        $productCount = isset($res['headers']['total']) ? (int)$res['headers']['total'] : null;
    }

    $catRes = $wc->getCategories(['per_page' => 1]);
    if (!$catRes['error']) {
        $categoryCount = isset($catRes['headers']['total']) ? (int)$catRes['headers']['total'] : null;
    }
}

$basalamConfigured = $basalam->isConfigured();
$basalamOnline = false;
$basalamError = null;
if ($basalamConfigured) {
    $ping = $basalam->ping();
    if ($ping['error']) {
        $basalamError = $ping['error'];
    } else {
        $basalamOnline = true;
    }
}

$mappedCount = null;
$syncIssueCount = null;
$lastSyncAt = '';
try {
    $db = Database::get();
    $mappedCount = (int)$db->query(
        'SELECT COUNT(*) FROM basalam_product_map WHERE basalam_product_id IS NOT NULL AND basalam_product_id > 0'
    )->fetchColumn();
    $syncIssueCount = (int)$db->query(
        "SELECT COUNT(*) FROM basalam_product_map WHERE sync_status IN ('error','failed') OR (sync_error IS NOT NULL AND TRIM(sync_error) <> '')"
    )->fetchColumn();
    $lastSyncAt = (string)($db->query(
        'SELECT MAX(last_synced_at) FROM basalam_product_map WHERE last_synced_at IS NOT NULL'
    )->fetchColumn() ?: '');
} catch (Throwable $e) {
    error_log('[wc-manager] dashboard Basalam stats failed: ' . $e->getMessage());
}

$storeUrl = trim((string)getSetting('store_url', ''));
$displayName = trim((string)($user['full_name'] ?? '')) ?: 'مدیر فروشگاه';

$pageTitle = 'داشبورد';
require __DIR__ . '/partials/header.php';
?>

<style>
.dashboard-shell{max-width:1440px;margin:0 auto}
.dashboard-hero{position:relative;overflow:hidden;border-radius:24px;padding:clamp(1.25rem,3vw,2rem);background:linear-gradient(135deg,#111827 0%,#18233a 58%,#153e75 100%);color:#fff;box-shadow:0 18px 50px rgba(15,23,42,.16);margin-bottom:1rem}
.dashboard-hero:before,.dashboard-hero:after{content:"";position:absolute;border-radius:999px;pointer-events:none}
.dashboard-hero:before{width:260px;height:260px;left:-100px;top:-130px;background:rgba(59,130,246,.2)}
.dashboard-hero:after{width:210px;height:210px;right:-60px;bottom:-120px;background:rgba(14,165,233,.12)}
.dashboard-hero-content{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1.25rem;flex-wrap:wrap}
.dashboard-kicker{display:inline-flex;align-items:center;gap:.45rem;color:#bfdbfe;font-size:.82rem;font-weight:700;margin-bottom:.55rem}
.dashboard-kicker-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.12)}
.dashboard-title{font-size:clamp(1.45rem,4vw,2.2rem);font-weight:900;letter-spacing:-.035em;margin:0;line-height:1.45}
.dashboard-subtitle{color:#cbd5e1;margin:.45rem 0 0;font-size:.92rem;line-height:1.9;max-width:680px}
.dashboard-hero-actions{display:flex;gap:.6rem;flex-wrap:wrap}
.dashboard-hero-btn{min-height:46px;border-radius:13px;padding:.65rem 1rem;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.45rem}
.dashboard-hero-btn-primary{background:#fff;color:#111827}
.dashboard-hero-btn-primary:hover{color:#111827;background:#f8fafc}
.dashboard-hero-btn-secondary{border:1px solid rgba(255,255,255,.18);color:#fff;background:rgba(255,255,255,.07)}
.dashboard-hero-btn-secondary:hover{color:#fff;background:rgba(255,255,255,.12)}
.dashboard-status-row{position:relative;z-index:1;display:flex;gap:.55rem;flex-wrap:wrap;margin-top:1.2rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,.1)}
.dashboard-status-pill{display:inline-flex;align-items:center;gap:.45rem;padding:.42rem .7rem;border-radius:999px;background:rgba(255,255,255,.07);font-size:.78rem;color:#e5e7eb}
.dashboard-status-pill .dot{width:7px;height:7px;border-radius:50%}
.dashboard-status-pill.ok .dot{background:#22c55e}.dashboard-status-pill.warn .dot{background:#f59e0b}.dashboard-status-pill.bad .dot{background:#ef4444}
.dashboard-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem;margin-bottom:1rem}
.metric-card{background:#fff;border:1px solid #e8ebef;border-radius:18px;padding:1.05rem;box-shadow:0 6px 22px rgba(15,23,42,.045);min-width:0}
.metric-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.metric-icon{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:#eff6ff;color:#2563eb;font-size:1.05rem}
.metric-icon.green{background:#ecfdf3;color:#15803d}.metric-icon.amber{background:#fffbeb;color:#d97706}.metric-icon.red{background:#fef2f2;color:#dc2626}
.metric-value{font-size:1.8rem;font-weight:900;letter-spacing:-.04em;margin-top:.85rem;line-height:1}
.metric-label{color:#6b7280;font-size:.8rem;margin-top:.45rem}
.dashboard-main{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(320px,.7fr);gap:1rem;align-items:start}
.panel-card{background:#fff;border:1px solid #e8ebef;border-radius:20px;padding:1.15rem;box-shadow:0 7px 24px rgba(15,23,42,.045)}
.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:.8rem;margin-bottom:1rem}
.panel-title{margin:0;font-size:1rem;font-weight:900}.panel-subtitle{color:#6b7280;font-size:.78rem;margin-top:.2rem}
.quick-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem}
.quick-action{position:relative;min-height:124px;border:1px solid #e9edf2;border-radius:16px;padding:1rem;text-decoration:none;color:#111827;background:#fff;display:flex;flex-direction:column;justify-content:space-between;transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
.quick-action:hover{color:#111827;transform:translateY(-2px);border-color:#cbd5e1;box-shadow:0 10px 24px rgba(15,23,42,.07)}
.quick-action-icon{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:#f3f4f6;color:#374151}
.quick-action.primary .quick-action-icon{background:#eff6ff;color:#2563eb}.quick-action.green .quick-action-icon{background:#ecfdf3;color:#15803d}.quick-action.violet .quick-action-icon{background:#f5f3ff;color:#7c3aed}
.quick-action strong{font-size:.9rem}.quick-action span{display:block;color:#8b95a5;font-size:.72rem;margin-top:.2rem}
.system-list{display:grid;gap:.15rem}
.system-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.85rem .1rem;border-bottom:1px solid #f0f2f5}
.system-row:last-child{border-bottom:0}
.system-label{display:flex;align-items:center;gap:.65rem;min-width:0}.system-label-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#f8fafc;color:#64748b;flex:0 0 34px}
.system-label strong{display:block;font-size:.84rem}.system-label small{display:block;color:#8b95a5;font-size:.71rem;margin-top:.14rem;max-width:190px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.system-state{display:inline-flex;align-items:center;gap:.4rem;font-size:.76rem;font-weight:800;white-space:nowrap}.system-state .dot{width:7px;height:7px;border-radius:50%}.system-state.ok{color:#15803d}.system-state.ok .dot{background:#22c55e}.system-state.bad{color:#b91c1c}.system-state.bad .dot{background:#ef4444}.system-state.muted{color:#6b7280}.system-state.muted .dot{background:#9ca3af}
.attention-card{margin-top:1rem;border-radius:18px;padding:1rem;display:flex;align-items:flex-start;gap:.8rem;background:<?= ($syncIssueCount ?? 0) > 0 ? '#fff7ed' : '#f0fdf4' ?>;border:1px solid <?= ($syncIssueCount ?? 0) > 0 ? '#fed7aa' : '#bbf7d0' ?>}
.attention-icon{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;flex:0 0 40px;background:#fff;color:<?= ($syncIssueCount ?? 0) > 0 ? '#ea580c' : '#16a34a' ?>}
.attention-card strong{font-size:.87rem}.attention-card p{margin:.25rem 0 0;color:#6b7280;font-size:.75rem;line-height:1.8}.attention-card a{font-size:.76rem;font-weight:800;text-decoration:none}
@media(max-width:991.98px){.dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.dashboard-main{grid-template-columns:1fr}.quick-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:767.98px){.dashboard-hero{border-radius:20px;padding:1.2rem}.dashboard-hero-content{display:block}.dashboard-hero-actions{margin-top:1rem;display:grid;grid-template-columns:1fr 1fr}.dashboard-hero-btn{padding:.65rem .55rem;font-size:.82rem}.dashboard-grid{gap:.65rem}.metric-card{border-radius:16px;padding:.9rem}.metric-value{font-size:1.55rem}.metric-icon{width:38px;height:38px}.quick-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}.quick-action{min-height:112px;padding:.85rem}.panel-card{border-radius:18px;padding:1rem}}
@media(max-width:380px){.dashboard-hero-actions{grid-template-columns:1fr}.quick-grid{grid-template-columns:1fr}.dashboard-grid{grid-template-columns:1fr 1fr}.metric-value{font-size:1.35rem}}
@media(prefers-reduced-motion:reduce){.quick-action{transition:none}.quick-action:hover{transform:none}}
</style>

<div class="dashboard-shell">
  <section class="dashboard-hero">
    <div class="dashboard-hero-content">
      <div>
        <div class="dashboard-kicker"><span class="dashboard-kicker-dot"></span> پنل مدیریت فروشگاه</div>
        <h1 class="dashboard-title">سلام <?= e($displayName) ?>، وضعیت فروشگاه اینجاست.</h1>
        <p class="dashboard-subtitle">محصولات سایت، اتصال باسلام و کارهای اصلی فروشگاه را از یک داشبورد واحد مدیریت کنید.</p>
      </div>
      <div class="dashboard-hero-actions">
        <a href="product_edit.php" class="dashboard-hero-btn dashboard-hero-btn-primary"><i class="fas fa-plus"></i> محصول جدید</a>
        <a href="products.php" class="dashboard-hero-btn dashboard-hero-btn-secondary"><i class="fas fa-boxes-stacked"></i> مدیریت محصولات</a>
      </div>
    </div>
    <div class="dashboard-status-row">
      <span class="dashboard-status-pill <?= $wooOnline ? 'ok' : ($wooConfigured ? 'bad' : 'warn') ?>"><span class="dot"></span> ووکامرس: <?= $wooOnline ? 'متصل' : ($wooConfigured ? 'خطای اتصال' : 'تنظیم نشده') ?></span>
      <span class="dashboard-status-pill <?= $basalamOnline ? 'ok' : ($basalamConfigured ? 'bad' : 'warn') ?>"><span class="dot"></span> باسلام: <?= $basalamOnline ? 'متصل' : ($basalamConfigured ? 'خطای اتصال' : 'تنظیم نشده') ?></span>
      <?php if ($lastSyncAt !== ''): ?><span class="dashboard-status-pill ok"><span class="dot"></span> آخرین سینک: <?= e($lastSyncAt) ?></span><?php endif; ?>
    </div>
  </section>

  <?php if (!$wooConfigured): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-4">
      <i class="fas fa-triangle-exclamation ms-2"></i>
      اتصال ووکامرس هنوز تنظیم نشده است.
      <?php if (Auth::isAdmin()): ?><a href="settings.php" class="alert-link">تنظیم اتصال</a><?php endif; ?>
    </div>
  <?php elseif ($wooError): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-4"><i class="fas fa-circle-exclamation ms-2"></i> خطا در اتصال به ووکامرس: <?= e($wooError) ?></div>
  <?php endif; ?>

  <section class="dashboard-grid" aria-label="آمار فروشگاه">
    <div class="metric-card">
      <div class="metric-head"><div class="metric-icon"><i class="fas fa-box"></i></div></div>
      <div class="metric-value"><?= $productCount !== null ? e($productCount) : '—' ?></div>
      <div class="metric-label">محصولات سایت</div>
    </div>
    <div class="metric-card">
      <div class="metric-head"><div class="metric-icon violet"><i class="fas fa-layer-group"></i></div></div>
      <div class="metric-value"><?= $categoryCount !== null ? e($categoryCount) : '—' ?></div>
      <div class="metric-label">دسته‌بندی‌ها</div>
    </div>
    <div class="metric-card">
      <div class="metric-head"><div class="metric-icon green"><i class="fas fa-link"></i></div></div>
      <div class="metric-value"><?= $mappedCount !== null ? e($mappedCount) : '—' ?></div>
      <div class="metric-label">متصل به باسلام</div>
    </div>
    <div class="metric-card">
      <div class="metric-head"><div class="metric-icon <?= ($syncIssueCount ?? 0) > 0 ? 'red' : 'green' ?>"><i class="fas <?= ($syncIssueCount ?? 0) > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i></div></div>
      <div class="metric-value"><?= $syncIssueCount !== null ? e($syncIssueCount) : '—' ?></div>
      <div class="metric-label">نیاز به بررسی سینک</div>
    </div>
  </section>

  <div class="dashboard-main">
    <section class="panel-card">
      <div class="panel-heading">
        <div><h2 class="panel-title">دسترسی سریع</h2><div class="panel-subtitle">کارهای پرکاربرد بدون رفتن بین منوها</div></div>
      </div>
      <div class="quick-grid">
        <a href="product_edit.php" class="quick-action primary"><div class="quick-action-icon"><i class="fas fa-plus"></i></div><div><strong>افزودن محصول</strong><span>ساخت محصول جدید در سایت</span></div></a>
        <a href="products.php" class="quick-action green"><div class="quick-action-icon"><i class="fas fa-boxes-stacked"></i></div><div><strong>محصولات</strong><span>سایت و باسلام در یک صفحه</span></div></a>
        <a href="categories.php" class="quick-action"><div class="quick-action-icon"><i class="fas fa-folder-tree"></i></div><div><strong>دسته‌بندی‌ها</strong><span>مدیریت ساختار فروشگاه</span></div></a>
        <a href="add-post.php" class="quick-action violet"><div class="quick-action-icon"><i class="fas fa-pen-nib"></i></div><div><strong>مقاله جدید</strong><span>ایجاد محتوای مجله</span></div></a>
        <a href="manage-posts.php" class="quick-action"><div class="quick-action-icon"><i class="fas fa-newspaper"></i></div><div><strong>مقالات</strong><span>مشاهده و مدیریت نوشته‌ها</span></div></a>
        <?php if (Auth::isAdmin()): ?><a href="basalam.php" class="quick-action green"><div class="quick-action-icon"><i class="fas fa-sliders"></i></div><div><strong>تنظیمات باسلام</strong><span>اتصال و نگاشت‌ها</span></div></a><?php endif; ?>
      </div>
    </section>

    <aside>
      <section class="panel-card">
        <div class="panel-heading"><div><h2 class="panel-title">وضعیت سیستم</h2><div class="panel-subtitle">اتصال سرویس‌های اصلی</div></div></div>
        <div class="system-list">
          <div class="system-row">
            <div class="system-label"><div class="system-label-icon"><i class="fab fa-wordpress"></i></div><div><strong>WooCommerce</strong><small><?= e($storeUrl !== '' ? $storeUrl : 'آدرس فروشگاه ثبت نشده') ?></small></div></div>
            <div class="system-state <?= $wooOnline ? 'ok' : ($wooConfigured ? 'bad' : 'muted') ?>"><span class="dot"></span><?= $wooOnline ? 'آنلاین' : ($wooConfigured ? 'خطا' : 'تنظیم نشده') ?></div>
          </div>
          <div class="system-row">
            <div class="system-label"><div class="system-label-icon"><i class="fas fa-store"></i></div><div><strong>Basalam</strong><small><?= $basalamConfigured ? 'Vendor #' . e($basalam->getVendorId()) : 'اتصال تنظیم نشده' ?></small></div></div>
            <div class="system-state <?= $basalamOnline ? 'ok' : ($basalamConfigured ? 'bad' : 'muted') ?>"><span class="dot"></span><?= $basalamOnline ? 'آنلاین' : ($basalamConfigured ? 'خطا' : 'تنظیم نشده') ?></div>
          </div>
          <div class="system-row">
            <div class="system-label"><div class="system-label-icon"><i class="fas fa-rotate"></i></div><div><strong>آخرین سینک باسلام</strong><small><?= e($lastSyncAt !== '' ? $lastSyncAt : 'هنوز سینکی ثبت نشده') ?></small></div></div>
            <div class="system-state <?= $lastSyncAt !== '' ? 'ok' : 'muted' ?>"><span class="dot"></span><?= $lastSyncAt !== '' ? 'ثبت شده' : '—' ?></div>
          </div>
        </div>
      </section>

      <div class="attention-card">
        <div class="attention-icon"><i class="fas <?= ($syncIssueCount ?? 0) > 0 ? 'fa-triangle-exclamation' : 'fa-shield-check' ?>"></i></div>
        <div>
          <?php if (($syncIssueCount ?? 0) > 0): ?>
            <strong><?= e($syncIssueCount) ?> مورد در سینک نیاز به بررسی دارد</strong>
            <p>از صفحه محصولات، بخش «نیاز به رسیدگی» وضعیت‌ها را بررسی و اصلاح کنید.</p>
            <a href="products.php">رفتن به محصولات <i class="fas fa-arrow-left me-1"></i></a>
          <?php else: ?>
            <strong>وضعیت سینک پایدار است</strong>
            <p>در حال حاضر خطای ثبت‌شده‌ای در نگاشت محصولات باسلام دیده نمی‌شود.</p>
          <?php endif; ?>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
