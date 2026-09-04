<?php
require_once 'includes/bootstrap.php';

function normTitle2(string $title): string {
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = str_replace(["\u{200c}","\u{200d}","\u{200e}","\u{200f}",'ي','ى','ك','ة','ۀ'],['','','','','ی','ی','ک','ه','ه'],$title);
    $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    return preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
}

function restorePilotMaps(PDO $db, array $productMap, array $variationMaps, int $wcId): void {
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM basalam_variation_map WHERE wc_product_id=:wc')->execute(['wc'=>$wcId]);
        $db->prepare('DELETE FROM basalam_product_map WHERE wc_product_id=:wc')->execute(['wc'=>$wcId]);
        $p = $db->prepare(
            'INSERT INTO basalam_product_map
             (wc_product_id,basalam_product_id,last_wc_hash,sync_status,sync_error,last_synced_at,created_at,updated_at)
             VALUES (:wc,:bp,:hash,:status,:error,:last_sync,:created,:updated)'
        );
        $p->execute([
            'wc'=>$productMap['wc_product_id'], 'bp'=>$productMap['basalam_product_id'],
            'hash'=>$productMap['last_wc_hash'], 'status'=>$productMap['sync_status'],
            'error'=>$productMap['sync_error'], 'last_sync'=>$productMap['last_synced_at'],
            'created'=>$productMap['created_at'], 'updated'=>$productMap['updated_at'],
        ]);
        if ($variationMaps) {
            $v = $db->prepare(
                'INSERT INTO basalam_variation_map
                 (wc_variation_id,wc_product_id,basalam_product_id,basalam_variation_id,sku,sync_status,sync_error,last_synced_at,created_at,updated_at)
                 VALUES (:wcv,:wcp,:bp,:bv,:sku,:status,:error,:last_sync,:created,:updated)'
            );
            foreach ($variationMaps as $r) {
                $v->execute([
                    'wcv'=>$r['wc_variation_id'], 'wcp'=>$r['wc_product_id'],
                    'bp'=>$r['basalam_product_id'], 'bv'=>$r['basalam_variation_id'],
                    'sku'=>$r['sku'], 'status'=>$r['sync_status'], 'error'=>$r['sync_error'],
                    'last_sync'=>$r['last_synced_at'], 'created'=>$r['created_at'], 'updated'=>$r['updated_at'],
                ]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

$wcId = 1056;
$oldId = 57614424;
$db = Database::get();
$wc = new WooCommerceClient();
$client = new BasalamClient();
$sync = new BasalamSync($wc, $client);

$wooRes = $wc->getProduct($wcId);
if ($wooRes['error']) throw new RuntimeException('Woo read failed: '.$wooRes['error']);
$product = (array)$wooRes['body'];
$wooTitle = (string)($product['name'] ?? '');
$type = (string)($product['type'] ?? 'simple');
$variations = [];
if ($type === 'variable') {
    $vr = $wc->getVariations($wcId, ['per_page'=>100]);
    if ($vr['error']) throw new RuntimeException('Woo variations read failed: '.$vr['error']);
    $variations = (array)$vr['body'];
}

$oldMap = $sync->getProductMap($wcId);
if (!$oldMap || (int)($oldMap['basalam_product_id'] ?? 0) !== $oldId) {
    throw new RuntimeException('Mapping changed; refusing pilot.');
}
$vs = $db->prepare('SELECT * FROM basalam_variation_map WHERE wc_product_id=:wc ORDER BY wc_variation_id');
$vs->execute(['wc'=>$wcId]);
$oldVariationMaps = $vs->fetchAll();

$catRef = new ReflectionMethod(BasalamSync::class, 'resolveBasalamCategory');
$catRef->setAccessible(true);
$category = $catRef->invoke($sync, $product);
$expectedCat = (int)($category['basalam_category_id'] ?? 0);
$expectedCatName = (string)($category['basalam_category_name'] ?? '');
if ($expectedCat <= 0) throw new RuntimeException('Expected category missing.');

$oldRes = $client->getProduct($oldId, true);
if ($oldRes['error']) throw new RuntimeException('Old product read failed: '.$oldRes['error']);
$old = (array)$oldRes['body'];
$oldTitle = (string)($old['name'] ?? $old['title'] ?? '');
$oldCat = (int)($old['category']['id'] ?? $old['category_id'] ?? 0);
if ($oldCat === $expectedCat) throw new RuntimeException('Old product is already in correct category.');
if (normTitle2($oldTitle) !== normTitle2($wooTitle)) throw new RuntimeException('Old/Woo title mismatch.');

echo 'PILOT_BEFORE=' . json_encode([
    'wc_product_id'=>$wcId,'old_basalam_id'=>$oldId,'old_category_id'=>$oldCat,
    'expected_category_id'=>$expectedCat,'expected_category_name'=>$expectedCatName,
    'old_status'=>$old['status'] ?? null,'old_showable'=>$old['is_showable'] ?? null,
    'old_available'=>$old['is_available'] ?? null,'woo_variations'=>count($variations)
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

$search = $client->getVendorProducts(['title'=>$wooTitle,'page'=>1,'per_page'=>100]);
if (!$search['error']) {
    $body = (array)($search['body'] ?? []);
    $items = $body['data'] ?? $body['products'] ?? $body;
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0 || $id === $oldId) continue;
            $name = (string)($item['name'] ?? $item['title'] ?? '');
            if (normTitle2($name) !== normTitle2($wooTitle)) continue;
            $fr = $client->getProduct($id, true);
            if ($fr['error']) continue;
            $fb = (array)$fr['body'];
            $cat = (int)($fb['category']['id'] ?? $fb['category_id'] ?? 0);
            if ($cat === $expectedCat) {
                throw new RuntimeException("Correct-category product already exists as Basalam #{$id}; refusing duplicate create.");
            }
        }
    }
}

$newId = 0;
$mapsChanged = false;
try {
    $productForCreate = $product;
    $productForCreate['name'] = $wooTitle . ' | باجی';

    $buildRef = new ReflectionMethod(BasalamSync::class, 'buildProductPayload');
    $buildRef->setAccessible(true);
    $payload = $buildRef->invoke($sync, $productForCreate, $variations, $expectedCat, true);

    if (in_array((string)getSetting('basalam_sync_attributes', '1'), ['1','true','yes','on'], true)) {
        $mapper = new BasalamAttributeMapper($client);
        $attrs = $mapper->build($product, $variations, $expectedCat, null);
        if (!empty($attrs['attributes'])) {
            $payload['product_attribute'] = array_values($attrs['attributes']);
        }
    }

    $warnings = [];
    if (in_array((string)getSetting('basalam_sync_images', '1'), ['1','true','yes','on'], true)) {
        $imgRef = new ReflectionMethod(BasalamSync::class, 'uploadWooImages');
        $imgRef->setAccessible(true);
        $imageArgs = [$product, &$warnings];
        $imageIds = $imgRef->invokeArgs($sync, $imageArgs);
        if ($imageIds) {
            $payload['photo'] = array_shift($imageIds);
            if ($imageIds) $payload['photos'] = $imageIds;
        }
    }

    $create = $client->createProduct($payload);
    if ($create['error']) throw new RuntimeException('Create in correct category failed: '.$create['error']);
    $body = (array)$create['body'];
    $newId = (int)($body['id'] ?? 0);
    if ($newId <= 0 || $newId === $oldId) throw new RuntimeException('Create returned invalid product ID.');

    $check = $client->getProduct($newId, true);
    if ($check['error']) throw new RuntimeException('New product read failed: '.$check['error']);
    $new = (array)$check['body'];
    $newCat = (int)($new['category']['id'] ?? $new['category_id'] ?? 0);
    if ($newCat !== $expectedCat) throw new RuntimeException("New category mismatch: {$newCat} != {$expectedCat}");

    echo 'PILOT_CREATED=' . json_encode([
        'new_basalam_id'=>$newId,
        'new_title'=>$new['name'] ?? $new['title'] ?? '',
        'category_id'=>$newCat,
        'category_name'=>$new['category']['title'] ?? $new['category']['name'] ?? '',
        'status'=>$new['status'] ?? null,
        'variants'=>count((array)($new['variants'] ?? $new['variant'] ?? [])),
        'image_warnings'=>$warnings,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM basalam_variation_map WHERE wc_product_id=:wc')->execute(['wc'=>$wcId]);
        $u = $db->prepare(
            "UPDATE basalam_product_map
             SET basalam_product_id=:newid,last_wc_hash=NULL,sync_status='matched',
                 sync_error='Pilot migrated from wrong Basalam category',last_synced_at=NULL
             WHERE wc_product_id=:wc AND basalam_product_id=:oldid"
        );
        $u->execute(['newid'=>$newId,'wc'=>$wcId,'oldid'=>$oldId]);
        if ($u->rowCount() !== 1) throw new RuntimeException('Failed to move product map.');
        $db->commit();
        $mapsChanged = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $captureRef = new ReflectionMethod(BasalamSync::class, 'captureCreatedVariationMaps');
    $captureRef->setAccessible(true);
    $capture = $captureRef->invoke($sync, $wcId, $newId, $variations, (array)($body['variants'] ?? []));
    echo 'PILOT_VARIATIONS=' . json_encode($capture, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $syncResult = $sync->syncProduct($wcId, true);
    echo 'PILOT_SYNC=' . json_encode($syncResult, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (empty($syncResult['success'])) throw new RuntimeException('Post-migration sync failed: '.($syncResult['message'] ?? 'unknown'));

    $finalMap = $sync->getProductMap($wcId);
    if ((int)($finalMap['basalam_product_id'] ?? 0) !== $newId) throw new RuntimeException('Final map does not point to new product.');
    $finalRead = $client->getProduct($newId, true);
    if ($finalRead['error']) throw new RuntimeException('Final product read failed.');
    $final = (array)$finalRead['body'];
    $finalCat = (int)($final['category']['id'] ?? $final['category_id'] ?? 0);
    if ($finalCat !== $expectedCat) throw new RuntimeException('Final category drifted.');

    echo 'PILOT_SUCCESS=' . json_encode([
        'wc_product_id'=>$wcId,'old_basalam_id'=>$oldId,'new_basalam_id'=>$newId,
        'expected_category_id'=>$expectedCat,'final_map'=>$finalMap,
        'final_title'=>$final['name'] ?? $final['title'] ?? '',
        'final_status'=>$final['status'] ?? null,
        'sync_warnings'=>$syncResult['warnings'] ?? [],
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    if ($mapsChanged) {
        restorePilotMaps($db, $oldMap, $oldVariationMaps, $wcId);
    }
    if ($newId > 0) {
        $client->updateProduct($newId, [
            'name'=>$wooTitle . ' | مهاجرت ناموفق #' . $newId,
            'status'=>3790,
            'sku'=>null,
        ]);
    }
    echo 'PILOT_ROLLBACK=' . json_encode([
        'error'=>$e->getMessage(),
        'map'=>$sync->getProductMap($wcId),
        'new_basalam_id'=>$newId,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    throw $e;
}
