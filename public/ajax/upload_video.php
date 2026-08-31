<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'متد درخواست نامعتبر است.'], 405);
}

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    jsonResponse(['success' => false, 'message' => 'فایل ویدیو دریافت نشد.'], 400);
}

$file = $_FILES['file'];
$errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($errorCode !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'حجم فایل از upload_max_filesize بیشتر است.',
        UPLOAD_ERR_FORM_SIZE => 'حجم فایل از محدودیت فرم بیشتر است.',
        UPLOAD_ERR_PARTIAL => 'آپلود فایل ناقص انجام شد.',
        UPLOAD_ERR_NO_FILE => 'هیچ فایلی انتخاب نشده است.',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت PHP در دسترس نیست.',
        UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل موقت روی دیسک ناموفق بود.',
        UPLOAD_ERR_EXTENSION => 'آپلود توسط افزونه PHP متوقف شد.',
    ];
    jsonResponse(['success' => false, 'message' => $messages[$errorCode] ?? ('خطای آپلود با کد ' . $errorCode)], 400);
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

$allowedVideos = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/ogg' => 'ogg',
    'video/quicktime' => 'mov',
];
if (!isset($allowedVideos[$mime])) {
    jsonResponse(['success' => false, 'message' => 'فرمت ویدیو مجاز نیست. فقط mp4, webm, ogg و mov مجاز هستند.'], 415);
}

$maxVideoSize = 50 * 1024 * 1024;
if ($size <= 0 || $size > $maxVideoSize) {
    jsonResponse(['success' => false, 'message' => 'حجم ویدیو باید بیشتر از صفر و حداکثر ۵۰ مگابایت باشد.'], 413);
}

$client = new WooCommerceClient();
if (!$client->isWpConfigured()) {
    jsonResponse(['success' => false, 'message' => 'اتصال وردپرس برای آپلود رسانه تنظیم نشده است.'], 503);
}

$fileName = basename((string)($file['name'] ?? ('video.' . $allowedVideos[$mime])));
$result = $client->uploadMedia($tmpName, $fileName);

if (!empty($result['error']) || ($result['status'] < 200 || $result['status'] >= 300)) {
    $status = ($result['status'] >= 400 && $result['status'] < 600) ? (int)$result['status'] : 502;
    jsonResponse([
        'success' => false,
        'message' => $result['error'] ?: 'آپلود ویدیو به وردپرس ناموفق بود.',
    ], $status);
}

$attachmentId = (int)($result['body']['id'] ?? 0);
$sourceUrl = (string)($result['body']['source_url'] ?? '');
if ($attachmentId <= 0) {
    jsonResponse(['success' => false, 'message' => 'آپلود انجام شد اما شناسه رسانه از وردپرس دریافت نشد.'], 502);
}

logActivity('upload_video', 'media', $fileName);
jsonResponse([
    'success' => true,
    'attachment_id' => $attachmentId,
    'url' => $sourceUrl,
    'name' => $fileName,
]);
