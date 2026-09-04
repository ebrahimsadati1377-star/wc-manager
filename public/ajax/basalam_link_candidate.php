<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$wcProductId = (int)($_POST['wc_product_id'] ?? 0);
$basalamProductId = (int)($_POST['basalam_product_id'] ?? 0);
if ($wcProductId <= 0 || $basalamProductId <= 0) {
    jsonResponse(['success'=>false,'message'=>'شناسه محصول سایت یا باسلام نامعتبر است.'], 422);
}

$wc = new WooCommerceClient();
$basalam = new BasalamClient();
if (!$wc->isConfigured() || !$basalam->isConfigured()) {
    jsonResponse(['success'=>false,'message'=>'اتصال WooCommerce یا باسلام تنظیم نشده است.'], 503);
}

$normalizeTitle = static function (string $title): string {
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = str_replace(["\u{200c}","\u{200d}","\u{200e}","\u{200f}",'ي','ى','ك','ة','ۀ'],['','','','','ی','ی','ک','ه','ه'],$title);
    $title = function_exists('mb_strtolower') ? mb_strtolower($title,'UTF-8') : strtolower($title);
    $title = preg_replace('/[^\p{L}\p{N}]+/u',' ',$title) ?? $title;
    return preg_replace('/\s+/u',' ',trim($title)) ?? trim($title);
};
$normalizeSku = static function (mixed $sku): string {
    $sku = trim((string)$sku);
    return function_exists('mb_strtolower') ? mb_strtolower($sku,'UTF-8') : strtolower($sku);
};

try {
    $wooRes = $wc->getProduct($wcProductId);
    if ($wooRes['error']) throw new RuntimeException('محصول سایت پیدا نشد: ' . $wooRes['error']);
    $woo = is_array($wooRes['body'] ?? null) ? $wooRes['body'] : [];

    $basalamRes = $basalam->getProduct($basalamProductId, true);
    if ($basalamRes['error']) throw new RuntimeException('محصول باسلام پیدا نشد: ' . $basalamRes['error']);
    $bp = is_array($basalamRes['body'] ?? null) ? $basalamRes['body'] : [];

    $wooSku = $normalizeSku($woo['sku'] ?? '');
    $basalamSku = $normalizeSku($bp['sku'] ?? '');
    $sameSku = $wooSku !== '' && $wooSku === $basalamSku;
    $sameTitle = $normalizeTitle((string)($woo['name'] ?? '')) !== ''
        && $normalizeTitle((string)($woo['name'] ?? '')) === $normalizeTitle((string)($bp['name'] ?? $bp['title'] ?? ''));

    if (!$sameSku && !$sameTitle) {
        jsonResponse(['success'=>false,'message'=>'برای جلوگیری از اتصال اشتباه، SKU یا عنوان دقیق دو محصول با هم تطبیق ندارد.'], 409);
    }

    $db = Database::get();
    $check = $db->prepare('SELECT wc_product_id, basalam_product_id FROM basalam_product_map WHERE wc_product_id = :wc OR basalam_product_id = :basalam');
    $check->execute(['wc'=>$wcProductId,'basalam'=>$basalamProductId]);
    foreach ($check->fetchAll() as $row) {
        $existingWoo = (int)($row['wc_product_id'] ?? 0);
        $existingBasalam = (int)($row['basalam_product_id'] ?? 0);
        if ($existingWoo === $wcProductId && $existingBasalam === $basalamProductId) {
            jsonResponse(['success'=>true,'message'=>'این دو محصول از قبل به هم متصل هستند.']);
        }
        if ($existingWoo === $wcProductId || $existingBasalam === $basalamProductId) {
            jsonResponse(['success'=>false,'message'=>'یکی از این محصولات قبلاً به محصول دیگری متصل شده است.'], 409);
        }
    }

    $stmt = $db->prepare('INSERT INTO basalam_product_map (wc_product_id, basalam_product_id, last_wc_hash, sync_status, sync_error, last_synced_at) VALUES (:wc, :basalam, NULL, :status, :error, NULL)');
    $stmt->execute([
        'wc'=>$wcProductId,
        'basalam'=>$basalamProductId,
        'status'=>'matched',
        'error'=>'Linked from unified products view; awaiting sync',
    ]);

    logActivity('basalam_manual_link','product','Woo #'.$wcProductId.' ↔ Basalam #'.$basalamProductId);
    jsonResponse(['success'=>true,'message'=>'محصول باسلام به Woo #'.$wcProductId.' متصل شد.']);
} catch (Throwable $e) {
    error_log('[wc-manager] Basalam candidate link failed: ' . $e->getMessage());
    jsonResponse(['success'=>false,'message'=>$e->getMessage()], 502);
}
