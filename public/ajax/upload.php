<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'متد درخواست نامعتبر است.'], 405);
}

if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    jsonResponse(['success' => false, 'message' => 'فایلی با نام image دریافت نشد.'], 400);
}

$file = $_FILES['image'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'حجم فایل از upload_max_filesize بیشتر است.',
        UPLOAD_ERR_FORM_SIZE => 'حجم فایل از محدودیت فرم بیشتر است.',
        UPLOAD_ERR_PARTIAL => 'آپلود فایل ناقص انجام شد.',
        UPLOAD_ERR_NO_FILE => 'هیچ فایلی برای آپلود انتخاب نشده است.',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت PHP در دسترس نیست.',
        UPLOAD_ERR_CANT_WRITE => 'PHP نتوانست فایل را روی دیسک بنویسد.',
        UPLOAD_ERR_EXTENSION => 'آپلود توسط یکی از افزونه‌های PHP متوقف شد.',
    ];
    jsonResponse(['success' => false, 'message' => $messages[$uploadError] ?? ('خطای آپلود با کد ' . $uploadError)], 400);
}

$tmpName = (string)($file['tmp_name'] ?? '');
$size = (int)($file['size'] ?? 0);
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    jsonResponse(['success' => false, 'message' => 'فایل موقت آپلود معتبر نیست.'], 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    jsonResponse(['success' => false, 'message' => 'تشخیص نوع فایل روی سرور در دسترس نیست.'], 500);
}
$mime = finfo_file($finfo, $tmpName);
finfo_close($finfo);

$allowedImages = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
$allowedVideos = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/ogg' => 'ogg',
    'video/quicktime' => 'mov',
];

$isVideo = false;
if (isset($allowedImages[$mime])) {
    $ext = $allowedImages[$mime];
    if ($size <= 0 || $size > MAX_UPLOAD_SIZE) {
        jsonResponse(['success' => false, 'message' => 'حجم تصویر باید بیشتر از صفر و حداکثر ۵ مگابایت باشد.'], 413);
    }
} elseif (isset($allowedVideos[$mime])) {
    $ext = $allowedVideos[$mime];
    $isVideo = true;
    $maxVideoSize = 50 * 1024 * 1024;
    if ($size <= 0 || $size > $maxVideoSize) {
        jsonResponse(['success' => false, 'message' => 'حجم ویدیو باید بیشتر از صفر و حداکثر ۵۰ مگابایت باشد.'], 413);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'فرمت فایل مجاز نیست.'], 415);
}

$subDir = date('Y/m');
$targetDir = rtrim(UPLOAD_DIR, '/\\') . '/' . $subDir;

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    error_log('[wc-manager] upload mkdir failed: ' . $targetDir);
    jsonResponse(['success' => false, 'message' => 'ساخت پوشه آپلود روی سرور ناموفق بود.'], 500);
}
if (!is_writable($targetDir)) {
    error_log('[wc-manager] upload directory not writable: ' . $targetDir);
    jsonResponse(['success' => false, 'message' => 'پوشه آپلود قابل نوشتن نیست. مجوزهای سرور را بررسی کنید.'], 500);
}

try {
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
} catch (Throwable $e) {
    error_log('[wc-manager] random filename generation failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'ساخت نام امن برای فایل ناموفق بود.'], 500);
}

$targetPath = $targetDir . '/' . $filename;
if (!move_uploaded_file($tmpName, $targetPath)) {
    error_log('[wc-manager] move_uploaded_file failed: ' . $targetPath);
    jsonResponse(['success' => false, 'message' => 'ذخیره فایل روی سرور ناموفق بود.'], 500);
}

@chmod($targetPath, 0644);

$publicUrl = uploadUrlBase() . '/uploads/products/' . $subDir . '/' . rawurlencode($filename);
$logType = $isVideo ? 'upload_video' : 'upload_image';
logActivity($logType, 'media', $filename);

jsonResponse([
    'success' => true,
    'url' => $publicUrl,
    'name' => (string)($file['name'] ?? $filename),
    'mime' => $mime,
    'size' => $size,
]);
