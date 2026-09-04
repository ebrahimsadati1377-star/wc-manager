<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'شناسه محصول باسلام معتبر نیست.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$client = new BasalamClient();
if (!$client->isConfigured()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'اتصال باسلام تنظیم نشده است.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$res = $client->getProduct($id, true);
if ($res['error']) {
    http_response_code(($res['status'] ?? 0) >= 400 ? (int)$res['status'] : 502);
    echo json_encode(['success' => false, 'message' => (string)$res['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$product = is_array($res['body'] ?? null) ? $res['body'] : [];
$status = $product['status'] ?? null;
$statusValue = is_array($status) ? (int)($status['value'] ?? 0) : (is_numeric($status) ? (int)$status : 0);
$statusName = is_array($status) ? trim((string)($status['name'] ?? '')) : (is_string($status) ? trim($status) : '');
$revision = is_array($product['revision'] ?? null) ? $product['revision'] : [];
$metadata = is_array($revision['metadata'] ?? null) ? $revision['metadata'] : [];

$reasons = [];
$addReason = static function (mixed $value) use (&$reasons): void {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_array($item)) {
                $text = trim((string)($item['name'] ?? $item['message'] ?? $item['description'] ?? ''));
                if ($text !== '') $reasons[$text] = true;
            } elseif (is_string($item) && trim($item) !== '') {
                $reasons[trim($item)] = true;
            }
        }
    } elseif (is_string($value) && trim($value) !== '') {
        $reasons[trim($value)] = true;
    }
};

$addReason($revision['rejection_reasons'] ?? null);
$addReason($revision['rejection_reason'] ?? null);
$addReason($product['rejection_reasons'] ?? null);
$addReason($product['rejection_reason'] ?? null);
$addReason($product['reject_reason'] ?? null);

$photoIndex = [];
foreach (array_merge(
    isset($product['photo']) && is_array($product['photo']) ? [$product['photo']] : [],
    is_array($product['photos'] ?? null) ? $product['photos'] : []
) as $photo) {
    if (!is_array($photo)) continue;
    $fileId = (int)($photo['id'] ?? 0);
    if ($fileId <= 0) continue;
    $photoIndex[$fileId] = (string)($photo['sm'] ?? $photo['xs'] ?? $photo['md'] ?? $photo['original'] ?? '');
}

$illegalPhotos = [];
foreach ((array)($metadata['illegal_photos'] ?? []) as $illegal) {
    if (!is_array($illegal)) continue;
    $fileId = (int)($illegal['file_id'] ?? $illegal['id'] ?? 0);
    $photoReasons = [];
    foreach ((array)($illegal['rejection_reasons'] ?? []) as $reason) {
        $text = is_array($reason)
            ? trim((string)($reason['name'] ?? $reason['message'] ?? $reason['description'] ?? ''))
            : trim((string)$reason);
        if ($text === '') continue;
        $photoReasons[$text] = true;
        $reasons[$text] = true;
    }
    $illegalPhotos[] = [
        'file_id' => $fileId,
        'thumbnail' => $photoIndex[$fileId] ?? '',
        'reasons' => array_keys($photoReasons),
    ];
}

$metadataDescription = trim((string)($metadata['description'] ?? ''));
$rejectedAt = trim((string)($revision['rejected_at'] ?? ''));

$data = [
    'success' => true,
    'product_id' => $id,
    'title' => (string)($product['title'] ?? $product['name'] ?? ''),
    'status' => ['name' => $statusName, 'value' => $statusValue],
    'is_showable' => array_key_exists('is_showable', $product) ? (bool)$product['is_showable'] : null,
    'is_available' => array_key_exists('is_available', $product) ? (bool)$product['is_available'] : null,
    'is_product_for_revision' => array_key_exists('is_product_for_revision', $product) ? (bool)$product['is_product_for_revision'] : null,
    'message' => $metadataDescription,
    'rejected_at' => $rejectedAt,
    'reasons' => array_keys($reasons),
    'illegal_photos' => $illegalPhotos,
];

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
