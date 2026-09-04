<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

setSetting('basalam_price_multiplier', '10');

$sync = new BasalamSync();
$result = $sync->syncProduct(2040, true);

$out = [
    'price_multiplier' => (string)getSetting('basalam_price_multiplier', ''),
    'sync_success' => (bool)($result['success'] ?? false),
    'wc_product_id' => (int)($result['wc_product_id'] ?? 0),
    'basalam_product_id' => (int)($result['basalam_product_id'] ?? 0),
    'message' => (string)($result['message'] ?? ''),
    'warnings' => $result['warnings'] ?? [],
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

if (empty($result['success'])) {
    exit(2);
}
