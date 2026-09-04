<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

if (!Auth::isAdmin()) {
    jsonResponse(['success' => false, 'message' => 'فقط مدیر می‌تواند مهاجرت دسته‌های باسلام را اجرا کند.'], 403);
}

$action = trim((string)($_POST['action'] ?? 'dry_run'));

try {
    $migrator = new BasalamCategoryMigrator();

    if ($action === 'dry_run') {
        $result = $migrator->dryRun();
        jsonResponse($result, !empty($result['success']) ? 200 : 422);
    }

    if ($action === 'migrate_batch') {
        $limit = max(1, min(5, (int)($_POST['limit'] ?? 5)));
        $ids = [];
        if (!empty($_POST['product_ids'])) {
            $raw = is_array($_POST['product_ids']) ? $_POST['product_ids'] : explode(',', (string)$_POST['product_ids']);
            $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        }
        $result = $migrator->migrateBatch($limit, $ids);
        jsonResponse($result, !empty($result['success']) ? 200 : 422);
    }

    jsonResponse(['success' => false, 'message' => 'عملیات مهاجرت نامعتبر است.'], 400);
} catch (Throwable $e) {
    error_log('[wc-manager] Basalam category migration endpoint failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'اجرای مهاجرت دسته‌های باسلام ناموفق بود.'], 500);
}
