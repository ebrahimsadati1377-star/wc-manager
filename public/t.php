<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$productId = (int)($_GET['id'] ?? 0);
if ($productId < 1) {
    http_response_code(404);
    exit('Not found');
}

try {
    logActivity('telegram_shortlink_click', 'product:' . $productId, 'telegram short link');
} catch (Throwable $e) {
}

$target = 'https://bajistyle.ir/?p=' . $productId
    . '&utm_source=telegram'
    . '&utm_medium=social'
    . '&utm_campaign=product_' . $productId
    . '&utm_content=bajistyle_channel';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);
header('Location: ' . $target, true, 302);
exit;
