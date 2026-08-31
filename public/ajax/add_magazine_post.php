<?php
/**
 * مسیر ذخیره این فایل: ajax/add_magazine_post.php
 */

// ۱. لود کردن پیش‌نیازها و فایل اصلی سیستم (تنظیم مسیر با دو بار برگشت به عقب)
require_once __DIR__ . '/../../includes/bootstrap.php';

// ۲. بررسی امنیتی برای اطمینان از لاگین بودن کاربر
Auth::requireLogin();

// تنظیم هدر خروجی به صورت JSON
header('Content-Type: application/json; charset=utf-8');

// ۳. دریافت اطلاعات ارسال شده (شامل فایل و داده‌های متنی)
$title      = isset($_POST['title']) ? trim($_POST['title']) : '';
$content    = isset($_POST['content']) ? trim($_POST['content']) : '';
$status     = isset($_POST['status']) ? trim($_POST['status']) : 'draft';
$categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

// ۴. اعتبارسنجی اولیه داده‌های ورودی
if (empty($title) || empty($content)) {
    echo json_encode([
        'success' => false,
        'message' => 'لطفاً عنوان و محتوای مقاله را وارد کنید.'
    ]);
    exit;
}

try {
    // ۵. ساخت نمونه از کلاینت پیشرفته
    $wc = new WooCommerceClient();

    // ۶. بررسی اینکه اطلاعات اتصال به وردپرس در دیتابیس ست شده باشد
    if (!$wc->isWpConfigured()) {
        echo json_encode([
            'success' => false,
            'message' => 'تنظیمات اتصال به وبلاگ (نام کاربری یا رمز عبور کاربردی وردپرس) در پنل تنظیم نشده است.'
        ]);
        exit;
    }

    // ۷. ارسال درخواست ساخت مقاله به وبلاگ وردپرس
    $categoryIds = [];
    if ($categoryId > 0) {
        $categoryIds = [$categoryId];
    }

    $result = $wc->createPostWithCategories($title, $content, $status, $categoryIds);

    // ۸. تحلیل پاسخ دریافتی از API
    if (!empty($result['error'])) {
        echo json_encode([
            'success' => false,
            'message' => 'خطا از سمت وردپرس: ' . $result['error']
        ]);
        exit;
    }

    $postId = $result['body']['id'] ?? null;

    // ۹. اگر تصویر شاخص آپلود شده، آن را به وردپرس ارسال کنیم
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = $_FILES['featured_image']['tmp_name'];
        $fileName = $_FILES['featured_image']['name'];

        // آپلود تصویر به کتابخانه رسانه وردپرس
        $uploadResult = $wc->uploadMedia($tmpFile, $fileName);

        if (!empty($uploadResult['error'])) {
            echo json_encode([
                'success' => false,
                'message' => 'مقاله ایجاد شد اما خطا در آپلود تصویر: ' . $uploadResult['error']
            ]);
            exit;
        }

        $mediaId = $uploadResult['body']['id'] ?? null;

        // تنظیم تصویر به عنوان featured image
        if ($mediaId && $postId) {
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

    echo json_encode([
        'success' => true,
        'message' => 'مقاله با موفقیت ثبت شد.',
        'post_id' => $postId
    ]);

} catch (Exception $e) {
    // مدیریت خطاهای غیرمنتظره سیستم
    echo json_encode([
        'success' => false,
        'message' => 'خطای سیستمی رخ داد: ' . $e->getMessage()
    ]);
}
exit;