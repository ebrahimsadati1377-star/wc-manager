<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (Auth::check()) {
    redirect('index.php');
}

$error = '';
$submittedUsername = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedUsername = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!checkCsrf()) {
        $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره وارد شوید.';
    } elseif ($submittedUsername === '' || $password === '') {
        $error = 'نام کاربری و رمز عبور را وارد کنید.';
    } elseif (Auth::isLoginRateLimited($submittedUsername)) {
        $minutes = max(1, (int)ceil(Auth::loginRetryAfter($submittedUsername) / 60));
        $error = 'تعداد تلاش‌های ورود بیش از حد مجاز است. حدود ' . $minutes . ' دقیقه دیگر دوباره تلاش کنید.';
    } elseif (Auth::attempt($submittedUsername, $password)) {
        redirect('index.php');
    } else {
        $error = Auth::isLoginRateLimited($submittedUsername)
            ? 'تعداد تلاش‌های ورود بیش از حد مجاز است. لطفاً بعداً دوباره تلاش کنید.'
            : 'نام کاربری یا رمز عبور صحیح نیست.';
    }
}

$isLocked = $submittedUsername !== '' && Auth::isLoginRateLimited($submittedUsername);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#111827">
<title>ورود | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<style>
:root {
    color-scheme: light;
    --bg: #f5f7fb;
    --card: rgba(255,255,255,.96);
    --text: #111827;
    --muted: #6b7280;
    --line: #e5e7eb;
    --primary: #1677ff;
    --primary-dark: #075ed1;
    --danger: #b42318;
    --danger-bg: #fef3f2;
}
* { box-sizing: border-box; }
html, body { margin: 0; min-height: 100%; }
body {
    min-height: 100vh;
    min-height: 100dvh;
    font-family: 'Vazirmatn', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    color: var(--text);
    background:
      radial-gradient(circle at 90% 5%, rgba(22,119,255,.13), transparent 30rem),
      radial-gradient(circle at 5% 95%, rgba(17,24,39,.07), transparent 26rem),
      var(--bg);
}
button, input { font: inherit; }
.login-page {
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(420px, 520px);
}
.brand-panel {
    position: relative;
    overflow: hidden;
    padding: clamp(2.5rem, 6vw, 6rem);
    background: linear-gradient(145deg, #0b1220 0%, #111827 58%, #172554 100%);
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.brand-panel::before,
.brand-panel::after {
    content: '';
    position: absolute;
    border-radius: 999px;
    pointer-events: none;
}
.brand-panel::before {
    width: 430px; height: 430px;
    left: -180px; top: -180px;
    background: rgba(37,99,235,.22);
    filter: blur(1px);
}
.brand-panel::after {
    width: 360px; height: 360px;
    right: -130px; bottom: -170px;
    background: rgba(14,165,233,.12);
}
.brand-content { position: relative; z-index: 1; max-width: 650px; }
.brand-mark {
    width: 62px; height: 62px;
    display: grid; place-items: center;
    border-radius: 18px;
    background: linear-gradient(135deg, #2f80ff, #0f5bd7);
    box-shadow: 0 18px 40px rgba(22,119,255,.28);
    font-size: 1.18rem;
    font-weight: 900;
    letter-spacing: -.04em;
    margin-bottom: 2rem;
}
.brand-panel h1 {
    margin: 0;
    font-size: clamp(2rem, 4vw, 3.5rem);
    line-height: 1.3;
    letter-spacing: -.04em;
}
.brand-panel p {
    margin: 1.25rem 0 0;
    color: #cbd5e1;
    font-size: clamp(1rem, 1.5vw, 1.12rem);
    line-height: 2;
    max-width: 560px;
}
.brand-pills {
    position: relative;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    gap: .65rem;
    margin-top: 2.5rem;
}
.brand-pill {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    min-height: 38px;
    padding: .5rem .8rem;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 999px;
    background: rgba(255,255,255,.06);
    color: #e5e7eb;
    font-size: .85rem;
    backdrop-filter: blur(8px);
}
.brand-pill-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; }
.brand-foot {
    position: relative;
    z-index: 1;
    color: #94a3b8;
    font-size: .8rem;
}
.login-side {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: clamp(1.25rem, 4vw, 3rem);
}
.login-card {
    width: 100%;
    max-width: 430px;
    background: var(--card);
    border: 1px solid rgba(229,231,235,.9);
    border-radius: 26px;
    padding: clamp(1.4rem, 4vw, 2.15rem);
    box-shadow: 0 24px 70px rgba(15,23,42,.10);
}
.mobile-brand {
    display: none;
    align-items: center;
    gap: .85rem;
    margin-bottom: 1.8rem;
}
.mobile-brand-mark {
    width: 48px; height: 48px;
    display: grid; place-items: center;
    border-radius: 14px;
    background: linear-gradient(135deg, #2f80ff, #0f5bd7);
    color: white;
    font-weight: 900;
    box-shadow: 0 10px 24px rgba(22,119,255,.22);
}
.mobile-brand strong { display: block; font-size: .95rem; }
.mobile-brand span { display: block; margin-top: .08rem; color: var(--muted); font-size: .75rem; }
.login-head { margin-bottom: 1.55rem; }
.login-head h2 { margin: 0; font-size: 1.7rem; letter-spacing: -.025em; }
.login-head p { margin: .5rem 0 0; color: var(--muted); font-size: .92rem; line-height: 1.8; }
.login-alert {
    display: flex;
    align-items: flex-start;
    gap: .65rem;
    margin-bottom: 1rem;
    padding: .85rem .95rem;
    border: 1px solid #fecdca;
    border-radius: 14px;
    background: var(--danger-bg);
    color: var(--danger);
    font-size: .86rem;
    line-height: 1.8;
}
.field { margin-bottom: 1rem; }
.field label {
    display: block;
    margin-bottom: .48rem;
    font-weight: 700;
    font-size: .86rem;
    color: #374151;
}
.input-wrap { position: relative; }
.field-input {
    width: 100%;
    min-height: 52px;
    border: 1px solid var(--line);
    border-radius: 14px;
    outline: 0;
    padding: 0 46px 0 46px;
    background: #fff;
    color: var(--text);
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.field-input::placeholder { color: #9ca3af; }
.field-input:focus {
    border-color: #67a6ff;
    box-shadow: 0 0 0 4px rgba(22,119,255,.10);
}
.field-icon {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    color: #9ca3af;
    pointer-events: none;
}
.password-toggle {
    position: absolute;
    left: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 38px;
    height: 38px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #6b7280;
    display: grid;
    place-items: center;
    cursor: pointer;
}
.password-toggle:hover { background: #f3f4f6; }
.password-toggle:focus-visible { outline: 3px solid rgba(22,119,255,.18); }
.login-button {
    width: 100%;
    min-height: 54px;
    border: 0;
    border-radius: 14px;
    background: linear-gradient(180deg, #2182ff 0%, #116deb 100%);
    color: #fff;
    font-weight: 800;
    font-size: 1rem;
    box-shadow: 0 10px 24px rgba(22,119,255,.22);
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
}
.login-button:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 14px 30px rgba(22,119,255,.28); }
.login-button:active:not(:disabled) { transform: translateY(0); }
.login-button:disabled { cursor: not-allowed; opacity: .58; box-shadow: none; }
.login-button .spinner {
    width: 17px; height: 17px;
    display: none;
    margin-left: .5rem;
    border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff;
    border-radius: 50%;
    vertical-align: middle;
    animation: spin .7s linear infinite;
}
.login-button.is-loading .spinner { display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }
.security-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    margin-top: 1.25rem;
    color: #8b95a5;
    font-size: .77rem;
    text-align: center;
}
.security-note svg { width: 15px; height: 15px; }
@media (max-width: 900px) {
    .login-page { grid-template-columns: 1fr; }
    .brand-panel { display: none; }
    .login-side { padding: max(1rem, env(safe-area-inset-top)) 1rem max(1rem, env(safe-area-inset-bottom)); }
    .mobile-brand { display: flex; }
}
@media (max-width: 520px) {
    body { background: #f5f7fb; }
    .login-side { align-items: center; padding: 1rem; }
    .login-card { border-radius: 22px; padding: 1.35rem 1.1rem 1.25rem; box-shadow: 0 16px 45px rgba(15,23,42,.08); }
    .login-head h2 { font-size: 1.5rem; }
    .field-input { min-height: 54px; font-size: 16px; }
    .login-button { min-height: 55px; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { scroll-behavior: auto !important; transition: none !important; animation-duration: .001ms !important; }
}
</style>
</head>
<body>
<main class="login-page">
  <section class="brand-panel" aria-hidden="true">
    <div class="brand-content">
      <div class="brand-mark">WC</div>
      <h1><?= e(APP_NAME) ?></h1>
      <p>مدیریت یکپارچه محصولات فروشگاه، همگام‌سازی باسلام و کنترل وضعیت انتشار از یک پنل مرکزی.</p>
      <div class="brand-pills">
        <span class="brand-pill"><span class="brand-pill-dot"></span> WooCommerce</span>
        <span class="brand-pill"><span class="brand-pill-dot"></span> باسلام</span>
        <span class="brand-pill"><span class="brand-pill-dot"></span> مدیریت امن</span>
      </div>
    </div>
    <div class="brand-foot">پنل مدیریت داخلی فروشگاه</div>
  </section>

  <section class="login-side">
    <div class="login-card">
      <div class="mobile-brand">
        <div class="mobile-brand-mark">WC</div>
        <div>
          <strong><?= e(APP_NAME) ?></strong>
          <span>پنل مدیریت فروشگاه</span>
        </div>
      </div>

      <header class="login-head">
        <h2>ورود به سامانه</h2>
        <p>برای دسترسی به مدیریت محصولات، اطلاعات حساب خود را وارد کنید.</p>
      </header>

      <?php if ($error): ?>
        <div class="login-alert" role="alert">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5"></path><path d="M12 17h.01"></path></svg>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

        <div class="field">
          <label for="username">نام کاربری</label>
          <div class="input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4.5 20c.8-4.1 3.3-6.2 7.5-6.2s6.7 2.1 7.5 6.2"></path></svg>
            <input id="username" type="text" name="username" class="field-input" autocomplete="username" inputmode="text" placeholder="نام کاربری" value="<?= e($submittedUsername) ?>" required autofocus>
          </div>
        </div>

        <div class="field">
          <label for="password">رمز عبور</label>
          <div class="input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
            <input id="password" type="password" name="password" class="field-input" autocomplete="current-password" placeholder="رمز عبور" required>
            <button type="button" class="password-toggle" id="passwordToggle" aria-label="نمایش رمز عبور" aria-pressed="false">
              <svg id="eyeIcon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.7"></circle></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="login-button" id="loginButton" <?= $isLocked ? 'disabled' : '' ?>>
          <span class="spinner" aria-hidden="true"></span>
          <span class="button-label"><?= $isLocked ? 'ورود موقتاً غیرفعال است' : 'ورود به پنل' ?></span>
        </button>
      </form>

      <div class="security-note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.6 7.7 7 10 4.4-2.3 7-5.4 7-10V6l-7-3Z"></path><path d="m9.5 12 1.7 1.7 3.6-3.9"></path></svg>
        <span>دسترسی محافظت‌شده به پنل مدیریت</span>
      </div>
    </div>
  </section>
</main>
<script>
(() => {
  const password = document.getElementById('password');
  const toggle = document.getElementById('passwordToggle');
  const form = document.getElementById('loginForm');
  const button = document.getElementById('loginButton');

  toggle?.addEventListener('click', () => {
    const visible = password.type === 'text';
    password.type = visible ? 'password' : 'text';
    toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
    toggle.setAttribute('aria-label', visible ? 'نمایش رمز عبور' : 'مخفی کردن رمز عبور');
  });

  form?.addEventListener('submit', () => {
    if (!button || button.disabled) return;
    button.classList.add('is-loading');
    button.disabled = true;
    const label = button.querySelector('.button-label');
    if (label) label.textContent = 'در حال ورود…';
  });
})();
</script>
</body>
</html>
