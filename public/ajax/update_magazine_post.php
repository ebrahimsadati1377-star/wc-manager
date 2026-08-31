<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
header('Content-Type: application/json');

// دریافت داده‌ها از FormData
$postId     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$title      = isset($_POST['title']) ? trim($_POST['title']) : '';
$content    = isset($_POST['content']) ? trim($_POST['content']) : '';
$status     = isset($_POST['status']) ? trim($_POST['status']) : 'draft';
$categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

if (!$postId) {
    echo json_encode(['success' => false, 'message' => 'شناسه مقاله نامعتبر است.']);
    exit;
}

if (empty($title) || empty($content)) {
    echo json_encode(['success' => false, 'message' => 'عنوان و محتوا نمی‌تواند خالی باشد.']);
    exit;
}

try {
    $wc = new WooCommerceClient();

    // ویرایش مقاله به همراه دسته‌بندی
    $categoryIds = [];
    if ($categoryId > 0) {
        $categoryIds = [$categoryId];
    }

    $result = $wc->updatePostWithCategories($postId, $title, $content, $status, $categoryIds);

    if (!empty($result['error'])) {
        echo json_encode(['success' => false, 'message' => 'خطا در ویرایش مقاله: ' . $result['error']]);
        exit;
    }

    // اگر تصویر جدید آپلود شده، آن را جایگزین کنیم
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = $_FILES['featured_image']['tmp_name'];
        $fileName = $_FILES['featured_image']['name'];

        // آپلود تصویر جدید
        $uploadResult = $wc->uploadMedia($tmpFile, $fileName);

        if (!empty($uploadResult['error'])) {
            echo json_encode([
                'success' => false,
                'message' => 'مقاله ویرایش شد اما خطا در آپلود تصویر: ' . $uploadResult['error']
            ]);
            exit;
        }

        $mediaId = $uploadResult['body']['id'] ?? null;

        // تنظیم تصویر جدید به عنوان featured image
        if ($mediaId) {
            $setFeaturedResult = $wc->setPostFeaturedImage($postId, $mediaId);

            if (!empty($setFeaturedResult['error'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'مقاله و تصویر آپلود شدند اما خطا در تنظیم تصویر شاخص: ' . $setFeaturedResult['error']
                ]);
                exit;
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'مقاله با موفقیت ویرایش شد.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()]);
}