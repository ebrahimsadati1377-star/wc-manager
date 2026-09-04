<?php
/** @var string $pageTitle */
$user = Auth::user();
$currentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$isDashboard = $currentPage === 'index.php';
$isProducts = in_array($currentPage, [
    'products.php',
    'product_edit.php',
    'basalam-catalog.php',
    'basalam-safe-images.php',
    'basalam-products.php',
], true);
$isCategories = $currentPage === 'categories.php';
$isContent = in_array($currentPage, ['manage-posts.php', 'add-post.php'], true);
$isAdminArea = in_array($currentPage, ['basalam.php', 'users.php', 'settings.php', 'chatgpt.php'], true);
$userName = trim((string)($user['full_name'] ?? '')) ?: 'کاربر';
$userRole = (($user['role'] ?? '') === 'admin') ? 'مدیر' : 'ویرایشگر';
$userInitial = function_exists('mb_substr') ? mb_substr($userName, 0, 1, 'UTF-8') : substr($userName, 0, 1);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#111827">
<meta name="csrf-token" content="<?= e(csrfToken()) ?>">
<title><?= e($pageTitle ?? APP_NAME) ?> | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<style>
:root{--app-nav-bg:#111827;--app-nav-border:rgba(255,255,255,.08);--app-nav-muted:#9ca3af;--app-nav-blue:#3b82f6}
.app-navbar{background:rgba(17,24,39,.97);border-bottom:1px solid var(--app-nav-border);box-shadow:0 7px 24px rgba(15,23,42,.12);backdrop-filter:blur(14px);padding:.62rem 0;z-index:1050}
.app-navbar .container-fluid{max-width:1480px}
.app-brand{display:flex;align-items:center;gap:.72rem;color:#fff;text-decoration:none;min-width:0}
.app-brand:hover{color:#fff}
.app-brand-mark{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;flex:0 0 38px;background:linear-gradient(135deg,#3485ff,#135fcf);box-shadow:0 8px 20px rgba(37,99,235,.25);font-size:.72rem;font-weight:900;letter-spacing:-.02em}
.app-brand-copy{min-width:0;line-height:1.2}
.app-brand-title{display:block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:900;font-size:.92rem}
.app-brand-subtitle{display:block;color:#7f8ba0;font-size:.64rem;margin-top:.16rem}
.app-navbar-toggler{width:44px;height:44px;border:1px solid rgba(255,255,255,.12);border-radius:13px;background:rgba(255,255,255,.04);display:grid;place-items:center;color:#e5e7eb;box-shadow:none!important}
.app-navbar-toggler:hover{background:rgba(255,255,255,.08)}
.app-navbar-toggler:focus-visible{outline:3px solid rgba(59,130,246,.28)}
.app-main-nav{gap:.2rem;margin-right:1.25rem}
.app-nav-link{display:flex!important;align-items:center;gap:.45rem;min-height:42px;padding:.52rem .72rem!important;border-radius:11px;color:#aeb8c8!important;font-size:.82rem;font-weight:700;transition:background .15s ease,color .15s ease}
.app-nav-link i{width:17px;text-align:center;font-size:.84rem}
.app-nav-link:hover{background:rgba(255,255,255,.06);color:#fff!important}
.app-nav-link.active{background:rgba(59,130,246,.15);color:#bfdbfe!important;box-shadow:inset 0 0 0 1px rgba(96,165,250,.12)}
.app-admin-link.active{background:rgba(59,130,246,.15);color:#bfdbfe!important}
.app-header-actions{display:flex;align-items:center;gap:.5rem;margin-right:auto}
.app-user-menu{border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.045);border-radius:13px;min-height:44px;padding:.32rem .45rem .32rem .72rem;color:#fff;display:flex;align-items:center;gap:.58rem;text-decoration:none}
.app-user-menu:hover,.app-user-menu:focus{color:#fff;background:rgba(255,255,255,.075)}
.app-avatar{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:#253047;color:#dbeafe;font-weight:900;font-size:.78rem}
.app-user-copy{line-height:1.2;max-width:130px}
.app-user-name{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.76rem;font-weight:800}
.app-user-role{display:block;color:#8793a6;font-size:.62rem;margin-top:.18rem}
.app-navbar .dropdown-menu{border:1px solid #e7e9ee;border-radius:15px;padding:.45rem;box-shadow:0 18px 45px rgba(15,23,42,.16);min-width:220px}
.app-navbar .dropdown-item{border-radius:10px;padding:.65rem .72rem;display:flex;align-items:center;gap:.6rem;font-size:.8rem;color:#374151}
.app-navbar .dropdown-item i{width:18px;text-align:center;color:#7b8798}
.app-navbar .dropdown-item:hover,.app-navbar .dropdown-item:focus{background:#f3f6fa;color:#111827}
.app-navbar .dropdown-item.active{background:#edf4ff;color:#155dcc}
.app-navbar .dropdown-divider{margin:.35rem .2rem;border-color:#edf0f3}
.app-logout-btn{width:100%;border:0;background:transparent;color:#b42318!important;text-align:right}
.app-logout-btn i{color:#dc2626!important}
.app-nav-section-label{display:none}
@media(max-width:991.98px){
  .app-navbar{padding:.56rem 0}
  .app-brand-title{max-width:190px}
  .app-navbar .navbar-collapse{margin-top:.7rem;padding:.72rem;background:#182131;border:1px solid rgba(255,255,255,.07);border-radius:17px;box-shadow:0 18px 35px rgba(0,0,0,.14)}
  .app-main-nav{margin:0;gap:.15rem}
  .app-nav-link{min-height:46px;padding:.65rem .75rem!important;font-size:.86rem}
  .app-nav-section-label{display:block;color:#68758b;font-size:.66rem;font-weight:800;padding:.55rem .75rem .25rem}
  .app-header-actions{display:block;margin:0;padding-top:.55rem;border-top:1px solid rgba(255,255,255,.07);margin-top:.45rem}
  .app-user-menu{width:100%;justify-content:flex-start;min-height:50px;padding:.45rem .55rem}
  .app-user-copy{max-width:none;flex:1}
  .app-user-menu::after{margin-right:auto}
  .app-navbar .dropdown-menu{position:static!important;transform:none!important;width:100%;margin-top:.45rem;background:#fff}
}
@media(max-width:480px){
  .app-brand-subtitle{display:none}
  .app-brand-title{max-width:175px;font-size:.86rem}
  .app-brand-mark{width:36px;height:36px;flex-basis:36px;border-radius:11px}
}
@media(prefers-reduced-motion:reduce){.app-nav-link{transition:none}}
</style>
<script>
(() => {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const nativeFetch = window.fetch.bind(window);

  window.fetch = (input, init = {}) => {
    const requestMethod = init.method || (input instanceof Request ? input.method : 'GET');
    const method = String(requestMethod).toUpperCase();

    if (token && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      try {
        const rawUrl = typeof input === 'string' || input instanceof URL ? input : input.url;
        const target = new URL(rawUrl, window.location.href);
        if (target.origin === window.location.origin) {
          const baseHeaders = init.headers || (input instanceof Request ? input.headers : undefined);
          const headers = new Headers(baseHeaders || {});
          if (!headers.has('X-CSRF-Token')) {
            headers.set('X-CSRF-Token', token);
          }
          init = { ...init, headers };
        }
      } catch (error) {
        console.error('Unable to attach CSRF token to request.', error);
      }
    }

    return nativeFetch(input, init);
  };
})();
</script>
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg app-navbar sticky-top" aria-label="منوی اصلی">
  <div class="container-fluid px-3 px-lg-4">
    <a class="app-brand" href="index.php" aria-label="داشبورد <?= e(APP_NAME) ?>">
      <span class="app-brand-mark">WC</span>
      <span class="app-brand-copy">
        <span class="app-brand-title"><?= e(APP_NAME) ?></span>
        <span class="app-brand-subtitle">مدیریت یکپارچه فروشگاه</span>
      </span>
    </a>

    <button class="navbar-toggler app-navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNavMenu" aria-controls="appNavMenu" aria-expanded="false" aria-label="باز کردن منو">
      <i class="fas fa-bars" aria-hidden="true"></i>
    </button>

    <div class="collapse navbar-collapse" id="appNavMenu">
      <div class="app-nav-section-label">دسترسی اصلی</div>
      <ul class="navbar-nav app-main-nav mb-0">
        <li class="nav-item">
          <a class="nav-link app-nav-link <?= $isDashboard ? 'active' : '' ?>" href="index.php" <?= $isDashboard ? 'aria-current="page"' : '' ?>>
            <i class="fas fa-grid-2" aria-hidden="true"></i><span>داشبورد</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link app-nav-link <?= $isProducts ? 'active' : '' ?>" href="products.php" <?= $isProducts ? 'aria-current="page"' : '' ?>>
            <i class="fas fa-box" aria-hidden="true"></i><span>محصولات</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link app-nav-link <?= $isCategories ? 'active' : '' ?>" href="categories.php" <?= $isCategories ? 'aria-current="page"' : '' ?>>
            <i class="fas fa-layer-group" aria-hidden="true"></i><span>دسته‌بندی‌ها</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link app-nav-link <?= $isContent ? 'active' : '' ?>" href="manage-posts.php" <?= $isContent ? 'aria-current="page"' : '' ?>>
            <i class="fas fa-pen-to-square" aria-hidden="true"></i><span>محتوا</span>
          </a>
        </li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link app-nav-link app-admin-link dropdown-toggle <?= $isAdminArea ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-sliders" aria-hidden="true"></i><span>مدیریت</span>
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= $currentPage === 'basalam.php' ? 'active' : '' ?>" href="basalam.php"><i class="fas fa-bag-shopping"></i> تنظیمات باسلام</a></li>
            <li><a class="dropdown-item <?= $currentPage === 'users.php' ? 'active' : '' ?>" href="users.php"><i class="fas fa-users"></i> کاربران</a></li>
            <li><a class="dropdown-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>" href="settings.php"><i class="fas fa-plug"></i> اتصال فروشگاه</a></li>
            <li><a class="dropdown-item <?= $currentPage === 'chatgpt.php' ? 'active' : '' ?>" href="chatgpt.php"><i class="fas fa-robot"></i> اتصال ChatGPT</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

      <div class="app-header-actions">
        <div class="dropdown">
          <a class="app-user-menu dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="app-avatar"><?= e($userInitial) ?></span>
            <span class="app-user-copy">
              <span class="app-user-name"><?= e($userName) ?></span>
              <span class="app-user-role"><?= e($userRole) ?></span>
            </span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="px-2 pt-1 pb-2">
              <div class="small fw-bold text-dark"><?= e($userName) ?></div>
              <div class="text-muted" style="font-size:.7rem">حساب <?= e($userRole) ?></div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <?php if (Auth::isAdmin()): ?>
            <li><a class="dropdown-item" href="users.php"><i class="fas fa-user-gear"></i> مدیریت کاربران</a></li>
            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-gear"></i> تنظیمات سیستم</a></li>
            <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li>
              <form action="logout.php" method="post" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button type="submit" class="dropdown-item app-logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> خروج از حساب</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>
<div class="container-fluid py-4 px-3 px-lg-4">
  <?php foreach (getFlashes() as $flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>