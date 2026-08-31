<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

if (empty($_FILES['image'])) {
    jsonResponse(['success' => false, 'message' => 'فایلی با نام image دریافت نشد! یا نام فیلد در JS اشتباه است یا حجم فایل از post_max_size سرور بیشتر است.']);
}

if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['image']['error'];
    $errMessage = "خطای ناشناخته ($errCode)";
    
    if ($errCode === 1) $errMessage = 'حجم فایل از upload_max_filesize در php.ini بیشتر است!';
    if ($errCode === 3) $errMessage = 'آپلود فایل به صورت ناقص انجام شد.';
    if ($errCode === 4) $errMessage = 'هیچ فایلی برای آپلود انتخاب نشده است.';
    
    jsonResponse(['success' => false, 'message' => $errMessage]);
}
$file = $_FILES['image'];

// ۱. تشخیص نوع واقعی MIME فایل
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// لیست فرمت‌های مجاز تصاویر و ویدیوها به همراه پسوند آن‌ها
$allowedImages = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

$allowedVideos = [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/ogg'       => 'ogg',
    'video/quicktime' => 'mov', // ویدیوهای ضبط شده با آیفون
];

$ext = '';
$isVideo = false;

// ۲. بررسی نوع فایل و اعمال محدودیت حجم اختصاصی
if (array_key_exists($mime, $allowedImages)) {
    $ext = $allowedImages[$mime];
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        jsonResponse(['success' => false, 'message' => 'حجم تصویر بیشتر از حد مجاز (۵ مگابایت) است.']);
    }
} elseif (array_key_exists($mime, $allowedVideos)) {
    $ext = $allowedVideos[$mime];
    $isVideo = true;
    
    // تعریف سقف حجم مجزا برای ویدیوها (مثلاً ۵۰ مگابایت)
    $maxVideoSize = 50 * 1024 * 1024; 
    if ($file['size'] > $maxVideoSize) {
        jsonResponse(['success' => false, 'message' => 'حجم ویدیو بیشتر از حد مجاز (۵۰ مگابایت) است.']);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'فرمت فایل مجاز نیست. فقط تصاویر استاندارد و ویدیوهای (mp4, webm) مجاز هستند.']);
}

// ۳. ایجاد ساختار پوشه و ذخیره‌سازی
$subDir = date('Y/m');
$targetDir = UPLOAD_DIR . '/' . $subDir;
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$filename = bin2hex(random_bytes(12)) . '.' . $ext;
$targetPath = $targetDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    jsonResponse(['success' => false, 'message' => 'ذخیره فایل روی سرور ناموفق بود. مجوز پوشه uploads را بررسی کنید.']);
}

$publicUrl = uploadUrlBase() . '/uploads/products/' . $subDir . '/' . $filename;

// ثبت لوگی مجزا بر اساس نوع فایل آپلود شده
$logType = $isVideo ? 'upload_video' : 'upload_image';
logActivity($logType, 'media', $filename);

jsonResponse([
    'success' => true,
    'url'     => $publicUrl,
    'name'    => $file['name'],
]);