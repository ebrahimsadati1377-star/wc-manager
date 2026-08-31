<?php
/** @var string $pageTitle */
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? APP_NAME) ?> | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php"><?= e(APP_NAME) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="index.php">داشبورد</a></li>
        <li class="nav-item"><a class="nav-link" href="products.php">محصولات</a></li>
        <li class="nav-item"><a class="nav-link" href="categories.php">دسته‌بندی‌ها</a></li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item"><a class="nav-link" href="users.php">کاربران</a></li>
        <li class="nav-item"><a class="nav-link" href="settings.php">تنظیمات اتصال</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item d-flex align-items-center text-light me-3">
          <span class="small">👤 <?= e($user['full_name']) ?> <span class="badge bg-secondary"><?= $user['role'] === 'admin' ? 'مدیر' : 'ویرایشگر' ?></span></span>
        </li>
        <li class="nav-item"><a class="nav-link" href="logout.php">خروج</a></li>
      </ul>
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
