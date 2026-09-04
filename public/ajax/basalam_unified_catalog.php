<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$scope = trim((string)($_POST['scope'] ?? 'stats'));
$allowedScopes = ['stats','linked','candidate','basalam_only','rejected','pending','unpublished','all_basalam','image_issue','category_issue'];
if (!in_array($scope, $allowedScopes, true)) {
    jsonResponse(['success' => false, 'message' => 'فیلتر نامعتبر است.'], 422);
}

$wc = new WooCommerceClient();
$basalam = new BasalamClient();
if (!$wc->isConfigured() || !$basalam->isConfigured()) {
    jsonResponse(['success' => false, 'message' => 'اتصال WooCommerce یا باسلام تنظیم نشده است.'], 503);
}

$normalizeTitle = static function (string $title): string {
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = str_replace(["\u{200c}", "\u{200d}", "\u{200e}", "\u{200f}", 'ي', 'ى', 'ك', 'ة', 'ۀ'], ['', '', '', '', 'ی', 'ی', 'ک', 'ه', 'ه'], $title);
    $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    return preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
};
$normalizeSku = static function (mixed $sku): string {
    $sku = trim((string)$sku);
    return function_exists('mb_strtolower') ? mb_strtolower($sku, 'UTF-8') : strtolower($sku);
};
$contains = static function (string $haystack, string $needle): bool {
    if ($needle === '') return false;
    return function_exists('mb_stripos')
        ? mb_stripos($haystack, $needle, 0, 'UTF-8') !== false
        : stripos($haystack, $needle) !== false;
};
$classifyMarket = static function (array $product) use ($contains): array {
    $status = $product['status'] ?? null;
    $value = is_array($status) ? (int)($status['value'] ?? 0) : (is_numeric($status) ? (int)$status : 0);
    $name = is_array($status) ? trim((string)($status['name'] ?? '')) : (is_string($status) ? trim($status) : '');
    $showable = array_key_exists('is_showable', $product) ? (bool)$product['is_showable'] : null;
    $available = array_key_exists('is_available', $product) ? (bool)$product['is_available'] : null;
    $normalized = str_replace(['ي','ك'], ['ی','ک'], $name);

    if ($value === 2976 || ($showable === true && $available === true)) return ['key'=>'available','label'=>$name ?: 'در دسترس','value'=>$value];
    if ($value === 3568 || $contains($normalized, 'در انتظار') || $contains($normalized, 'بررسی')) return ['key'=>'pending','label'=>$name ?: 'در انتظار بررسی','value'=>$value];
    if ($value === 3567 || $contains($normalized, 'تایید نشده') || $contains($normalized, 'رد شده') || $contains($normalized, 'ردشده')) return ['key'=>'rejected','label'=>$name ?: 'تایید نشده','value'=>$value];
    if ($value === 3790 || $contains($normalized, 'منتشر نشده') || $contains($normalized, 'عدم انتشار')) return ['key'=>'unpublished','label'=>$name ?: 'منتشر نشده','value'=>$value];
    if ($showable === false && $available === false) return ['key'=>'inactive','label'=>$name ?: 'غیرفعال','value'=>$value];
    return ['key'=>'unknown','label'=>$name ?: 'نامشخص','value'=>$value];
};

try {
    $wooProducts = [];
    for ($page = 1; $page <= 50; $page++) {
        $res = $wc->getProducts(['page'=>$page,'per_page'=>100,'orderby'=>'id','order'=>'asc']);
        if ($res['error']) throw new RuntimeException('خواندن محصولات سایت ناموفق بود: ' . $res['error']);
        $batch = is_array($res['body'] ?? null) ? $res['body'] : [];
        foreach ($batch as $product) {
            if (!is_array($product) || !in_array((string)($product['type'] ?? ''), ['simple','variable'], true)) continue;
            $id = (int)($product['id'] ?? 0);
            if ($id > 0) $wooProducts[$id] = $product;
        }
        $totalPages = max(1, (int)($res['headers']['total_pages'] ?? 1));
        if ($page >= $totalPages || count($batch) < 100) break;
    }

    $basalamProducts = [];
    for ($page = 1; $page <= 50; $page++) {
        $res = $basalam->getVendorProducts(['page'=>$page,'per_page'=>100]);
        if ($res['error']) throw new RuntimeException('خواندن محصولات باسلام ناموفق بود: ' . $res['error']);
        $body = $res['body'] ?? [];
        $batch = $body['data'] ?? $body['products'] ?? $body;
        if (!is_array($batch) || !$batch) break;
        $count = 0;
        foreach ($batch as $product) {
            if (!is_array($product)) continue;
            $id = (int)($product['id'] ?? 0);
            if ($id <= 0) continue;
            $count++;
            $basalamProducts[$id] = $product;
        }
        if ($count < 100) break;
    }

    $maps = [];
    $stmt = Database::get()->query('SELECT wc_product_id, basalam_product_id, last_synced_at FROM basalam_product_map WHERE basalam_product_id IS NOT NULL AND basalam_product_id > 0');
    foreach ($stmt->fetchAll() as $row) {
        $bid = (int)($row['basalam_product_id'] ?? 0);
        if ($bid > 0) $maps[$bid] = ['wc_id'=>(int)$row['wc_product_id'],'last_synced_at'=>(string)($row['last_synced_at'] ?? '')];
    }

    $wooBySku = [];
    $wooByTitle = [];
    foreach ($wooProducts as $wooId => $product) {
        $sku = $normalizeSku($product['sku'] ?? '');
        if ($sku !== '') $wooBySku[$sku][] = $wooId;
        $title = $normalizeTitle((string)($product['name'] ?? ''));
        if ($title !== '') $wooByTitle[$title][] = $wooId;
    }

    $stats = ['total'=>count($basalamProducts),'linked'=>0,'candidate'=>0,'basalam_only'=>0,'rejected'=>0,'pending'=>0,'unpublished'=>0,'inactive'=>0,'available'=>0];
    $items = [];

    foreach ($basalamProducts as $id => $product) {
        $market = $classifyMarket($product);
        if (isset($stats[$market['key']])) $stats[$market['key']]++;

        $relation = ['key'=>'basalam_only','label'=>'فقط در باسلام','woo_id'=>0,'method'=>''];
        if (!empty($maps[$id]['wc_id'])) {
            $relation = ['key'=>'linked','label'=>'متصل به سایت','woo_id'=>(int)$maps[$id]['wc_id'],'method'=>'map'];
            $stats['linked']++;
        } else {
            $candidate = 0;
            $method = '';
            $sku = $normalizeSku($product['sku'] ?? '');
            if ($sku !== '' && count($wooBySku[$sku] ?? []) === 1) {
                $candidate = (int)$wooBySku[$sku][0];
                $method = 'SKU';
            }
            if ($candidate <= 0) {
                $title = $normalizeTitle((string)($product['name'] ?? $product['title'] ?? ''));
                if ($title !== '' && count($wooByTitle[$title] ?? []) === 1) {
                    $candidate = (int)$wooByTitle[$title][0];
                    $method = 'عنوان دقیق';
                }
            }
            if ($candidate > 0) {
                $relation = ['key'=>'candidate','label'=>'در سایت هست؛ ادغام نشده','woo_id'=>$candidate,'method'=>$method];
                $stats['candidate']++;
            } else {
                $stats['basalam_only']++;
            }
        }

        $imageIssue = false;
        $categoryIssue = false;
        if (in_array($scope, ['image_issue','category_issue'], true) && $market['key'] === 'rejected') {
            $detailRes = $basalam->getProduct($id, true);
            if (!$detailRes['error']) {
                $full = is_array($detailRes['body'] ?? null) ? $detailRes['body'] : [];
                $revision = is_array($full['revision'] ?? null) ? $full['revision'] : [];
                $metadata = is_array($revision['metadata'] ?? null) ? $revision['metadata'] : [];
                $issueText = json_encode([
                    $metadata,
                    $revision['rejection_reasons'] ?? null,
                    $revision['rejection_reason'] ?? null,
                    $full['rejection_reasons'] ?? null,
                    $full['rejection_reason'] ?? null,
                    $full['reject_reason'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                $imageIssue = !empty($metadata['illegal_photos']);
                foreach (['تصویر','عکس','photo','حجاب','مو','چهره','پوشش','فیلتر','5031'] as $needle) {
                    if ($contains($issueText, $needle)) { $imageIssue = true; break; }
                }
                foreach (['دسته','category','6046'] as $needle) {
                    if ($contains($issueText, $needle)) { $categoryIssue = true; break; }
                }
            }
        }

        $matchesScope = match ($scope) {
            'stats' => false,
            'linked' => $relation['key'] === 'linked',
            'candidate' => $relation['key'] === 'candidate',
            'basalam_only' => $relation['key'] === 'basalam_only',
            'rejected' => $market['key'] === 'rejected',
            'pending' => $market['key'] === 'pending',
            'unpublished' => $market['key'] === 'unpublished',
            'all_basalam' => true,
            'image_issue' => $imageIssue,
            'category_issue' => $categoryIssue,
            default => false,
        };
        if (!$matchesScope) continue;

        $photo = is_array($product['photo'] ?? null) ? $product['photo'] : [];
        $items[] = [
            'basalam_id' => $id,
            'name' => (string)($product['name'] ?? $product['title'] ?? ('#'.$id)),
            'sku' => trim((string)($product['sku'] ?? '')),
            'photo' => (string)($photo['sm'] ?? $photo['xs'] ?? $photo['md'] ?? ''),
            'market' => $market,
            'showable' => array_key_exists('is_showable', $product) ? (bool)$product['is_showable'] : null,
            'available' => array_key_exists('is_available', $product) ? (bool)$product['is_available'] : null,
            'relation' => $relation,
            'last_synced_at' => (string)($maps[$id]['last_synced_at'] ?? ''),
        ];
    }

    usort($items, static fn(array $a, array $b): int => $b['basalam_id'] <=> $a['basalam_id']);
    jsonResponse(['success'=>true,'scope'=>$scope,'stats'=>$stats,'products'=>$items]);
} catch (Throwable $e) {
    error_log('[wc-manager] unified Basalam catalog failed: ' . $e->getMessage());
    jsonResponse(['success'=>false,'message'=>$e->getMessage()], 502);
}
