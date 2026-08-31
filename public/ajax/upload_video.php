<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

if (empty($_FILES['file'])) {
    jsonResponse(['success' => false, 'message' => 'فایل ویدیو دریافت نشد.']);
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'];
    $errMessage = "خطای ناشناخته ($errCode)";

    if ($errCode === 1) $errMessage = 'حجم فایل از upload_max_filesize در php.ini بیشتر است!';
    if ($errCode === 3) $errMessage = 'آپلود فایل به صورت ناقص انجام شد.';
    if ($errCode === 4) $errMessage = 'هیچ فایلی برای آپلود انتخاب نشده است.';

    jsonResponse(['success' => false, 'message' => $errMessage]);
}

$file = $_FILES['file'];

// Check MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedVideos = [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/ogg'       => 'ogg',
    'video/quicktime' => 'mov',
];

if (!array_key_exists($mime, $allowedVideos)) {
    jsonResponse(['success' => false, 'message' => 'فرمت ویدیو مجاز نیست. فقط mp4, webm, ogg, mov مجاز هستند.']);
}

// Size check (50MB)
$maxVideoSize = 50 * 1024 * 1024;
if ($file['size'] > $maxVideoSize) {
    jsonResponse(['success' => false, 'message' => 'حجم ویدیو بیشتر از حد مجاز (۵۰ مگابایت) است.']);
}

// Upload to WP Media Library via REST API
$wc = new WooCommerceClient();

if (!$wc->isWpConfigured()) {
    jsonResponse(['success' => false, 'message' => 'برای آپلود به کتابخانه رسانه، نام کاربری و رمز عبور کاربردی وردپرس در تنظیمات لازم است.']);
}

$fileData = file_get_contents($file['tmp_name']);
$fileName = basename($file['name']);

$url = $wc->getBaseUrl() . '/wp-json/wp/v2/media';

$ch = curl_init();
$headers = [
    'Content-Disposition: attachment; filename="' . $fileName . '"',
    'Content-Type: ' . $mime,
    'Authorization: Basic ' . base64_encode($wc->getWpUsername() . ':' . $wc->getWpAppPassword())
];

curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $fileData,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$curlError = curl_error($ch);
$status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($response === false) {
    jsonResponse(['success' => false, 'message' => 'خطای آپلود به وردپرس: ' . $curlError]);
}

$rawBody = substr($response, $headerLen);
$decoded = json_decode($rawBody, true);

if ($status >= 400) {
    $error = $decoded['message'] ?? ('خطای HTTP ' . $status);
    jsonResponse(['success' => false, 'message' => $error]);
}

$attachmentId = $decoded['id'] ?? 0;
$sourceUrl    = $decoded['source_url'] ?? '';

if (!$attachmentId) {
    jsonResponse(['success' => false, 'message' => 'آپلود موفق اما شناسه پیوست دریافت نشد.']);
}

logActivity('upload_video', 'media', $fileName);

jsonResponse([
    'success'       => true,
    'attachment_id' => (int)$attachmentId,
    'url'           => $sourceUrl,
    'name'          => $fileName,
]);