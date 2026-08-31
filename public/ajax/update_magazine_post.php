<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requirePostAndCsrfOrFail();

$postId = (int)($_POST['id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$content = trim((string)($_POST['content'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'draft'));
$categoryId = (int)($_POST['category_id'] ?? 0);
$allowedStatuses = ['publish', 'draft', 'pending', 'private'];

if ($postId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه مقاله نامعتبر است.'], 422);
}
if ($title === '' || $content === '') {
    jsonResponse(['success' => false, 'message' => 'عنوان و محتوا نمی‌تواند خالی باشد.'], 422);
}
if (mb_strlen($title, 'UTF-8') > 300) {
    jsonResponse(['success' => false, 'message' => 'عنوان مقاله بیش از حد طولانی است.'], 422);
}
if (!in_array($status, $allowedStatuses, true)) {
    jsonResponse(['success' => false, 'message' => 'وضعیت مقاله نامعتبر است.'], 422);
}

$featuredImage = $_FILES['featured_image'] ?? null;
if (is_array($featuredImage) && ($featuredImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (($featuredImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'آپلود تصویر شاخص ناموفق بود.'], 422);
    }

    $tmpName = (string)($featuredImage['tmp_name'] ?? '');
    $size = (int)($featuredImage['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName) || $size <= 0 || $size > 10 * 1024 * 1024) {
        jsonResponse(['success' => false, 'message' => 'فایل تصویر شاخص نامعتبر است یا حجم آن بیش از ۱۰ مگابایت است.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        jsonResponse(['success' => false, 'message' => 'فرمت تصویر شاخص مجاز نیست.'], 422);
    }
}

try {
    $wc = new WooCommerceClient();
    if (!$wc->isWpConfigured()) {
        jsonResponse(['success' => false, 'message' => 'تنظیمات اتصال به وردپرس کامل نیست.'], 422);
    }

    $categoryIds = $categoryId > 0 ? [$categoryId] : [];
    $result = $wc->updatePostWithCategories($postId, $title, $content, $status, $categoryIds);
    if (!empty($result['error'])) {
        error_log('[wc-manager] update magazine post failed post=' . $postId . ': ' . $result['error']);
        jsonResponse(['success' => false, 'message' => 'ویرایش مقاله در وردپرس ناموفق بود.'], 502);
    }

    if (is_array($featuredImage) && ($featuredImage['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $uploadResult = $wc->uploadMedia(
            (string)$featuredImage['tmp_name'],
            basename((string)($featuredImage['name'] ?? 'featured-image'))
        );

        if (!empty($uploadResult['error'])) {
            error_log('[wc-manager] update featured image upload failed post=' . $postId . ': ' . $uploadResult['error']);
            jsonResponse(['success' => false, 'message' => 'مقاله ویرایش شد، اما آپلود تصویر جدید ناموفق بود.'], 502);
        }

        $mediaId = (int)($uploadResult['body']['id'] ?? 0);
        if ($mediaId > 0) {
            $setFeaturedResult = $wc->setPostFeaturedImage($postId, $mediaId);
            if (!empty($setFeaturedResult['error'])) {
                error_log('[wc-manager] update featured image assignment failed post=' . $postId . ': ' . $setFeaturedResult['error']);
                jsonResponse(['success' => false, 'message' => 'تصویر آپلود شد، اما تنظیم تصویر شاخص ناموفق بود.'], 502);
            }
        }
    }

    logActivity('update_magazine_post', 'post', (string)$postId);
    jsonResponse(['success' => true, 'message' => 'مقاله با موفقیت ویرایش شد.']);
} catch (Throwable $e) {
    error_log('[wc-manager] update magazine post exception: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطای داخلی هنگام ویرایش مقاله رخ داد.'], 500);
}
