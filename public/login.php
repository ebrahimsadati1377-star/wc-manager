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
        $error = 'نشست منقضی شده، صفحه را رفرش کنید.';
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
            : 'نام کاربری یا رمز عبور اشتباه است.';
    }
}

$isLocked = $submittedUsername !== '' && Auth::isLoginRateLimited($submittedUsername);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body d-flex align-items-center justify-content-center">
  <div class="card login-card shadow">
    <div class="card-body p-4">
      <h4 class="text-center mb-3"><?= e(APP_NAME) ?></h4>
      <p class="text-center text-muted mb-4">برای ادامه وارد شوید</p>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="mb-3">
          <label class="form-label">نام کاربری</label>
          <input type="text" name="username" class="form-control" autocomplete="username" value="<?= e($submittedUsername) ?>" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">رمز عبور</label>
          <input type="password" name="password" class="form-control" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100" <?= $isLocked ? 'disabled' : '' ?>>ورود</button>
      </form>
    </div>
  </div>
</body>
</html>
