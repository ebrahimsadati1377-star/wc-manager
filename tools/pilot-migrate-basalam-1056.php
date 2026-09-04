<?php
require_once 'includes/bootstrap.php';

function normTitle(string $title): string {
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = str_replace(["\u{200c}","\u{200d}","\u{200e}","\u{200f}",'ي','ى','ك','ة','ۀ'],['','','','','ی','ی','ک','ه','ه'],$title);
    $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    return preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
}

function restoreMaps(PDO $db, array $productMap, array $variationMaps, int $wcId): void {
    $db->beginTransaction();
    try {
        $d1 = $db->prepare('DELETE FROM basalam_variation_map WHERE wc_product_id = :wc');
        $d1->execute(['wc' => $wcId]);
        $d2 = $db->prepare('DELETE FROM basalam_product_map WHERE wc_product_id = :wc');
        $d2->execute(['wc' => $wcId]);

        $p = $db->prepare(
            'INSERT INTO basalam_product_map
            (wc_product_id, basalam_product_id, last_wc_hash, sync_status, sync_error, last_synced_at, created_at, updated_at)
            VALUES (:wc_product_id, :basalam_product_id, :last_wc_hash, :sync_status, :sync_error, :last_synced_at, :created_at, :updated_at)'
        );
        $p->execute([
            'wc_product_id' => $productMap['wc_product_id'],
            'basalam_product_id' => $productMap['basalam_product_id'],
            'last_wc_hash' => $productMap['last_wc_hash'],
            'sync_status' => $productMap['sync_status'],
            'sync_error' => $productMap['sync_error'],
            'last_synced_at' => $productMap['last_synced_at'],
            'created_at' => $productMap['created_at'],
            'updated_at' => $productMap['updated_at'],
        ]);

        if ($variationMaps) {
            $v = $db->prepare(
                'INSERT INTO basalam_variation_map
                (wc_variation_id, wc_product_id, basalam_product_id, basalam_variation_id, sku, sync_status, sync_error, last_synced_at, created_at, updated_at)
                VALUES (:wc_variation_id, :wc_product_id, :basalam_product_id, :basalam_variation_id, :sku, :sync_status, :sync_error, :last_synced_at, :created_at, :updated_at)'
            );
            foreach ($variationMaps as $row) {
                $v->execute([
                    'wc_variation_id' => $row['wc_variation_id'],
                    'wc_product_id' => $row['wc_product_id'],
                    'basalam_product_id' => $row['basalam_product_id'],
                    'basalam_variation_id' => $row['basalam_variation_id'],
                    'sku' => $row['sku'],
                    'sync_status' => $row['sync_status'],
                    'sync_error' => $row['sync_error'],
                    'last_synced_at' => $row['last_synced_at'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
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
$expectedOldBasalamId = 57614424;

$db = Database::get();
$wc = new WooCommerceClient();
$client = new BasalamClient();
$sync = new BasalamSync($wc, $client);

$wooRes = $wc->getProduct($wcId);
if ($wooRes['error']) throw new RuntimeException('Woo read failed: ' . $wooRes['error']);
$woo = (array)$wooRes['body'];
$wooTitle = (string)($woo['name'] ?? '');
$wooSku = trim((string)($woo['sku'] ?? ''));

$currentMap = $sync->getProductMap($wcId);
if (!$currentMap || (int)($currentMap['basalam_product_id'] ?? 0) !== $expectedOldBasalamId) {
    throw new RuntimeException('Current mapping changed; refusing migration.');
}
$variationStmt = $db->prepare('SELECT * FROM basalam_variation_map WHERE wc_product_id = :wc ORDER BY wc_variation_id');
$variationStmt->execute(['wc' => $wcId]);
$variationBackup = $variationStmt->fetchAll();

$catRef = new ReflectionMethod(BasalamSync::class, 'resolveBasalamCategory');
$catRef->setAccessible(true);
$expected = $catRef->invoke($sync, $woo);
if (!is_array($expected) || (int)($expected['basalam_category_id'] ?? 0) <= 0) {
    throw new RuntimeException('Expected Basalam category could not be resolved.');
}
$expectedCategoryId = (int)$expected['basalam_category_id'];
$expectedCategoryName = (string)($expected['basalam_category_name'] ?? '');

$oldRes = $client->getProduct($expectedOldBasalamId, true);
if ($oldRes['error']) throw new RuntimeException('Old Basalam read failed: ' . $oldRes['error']);
$old = (array)$oldRes['body'];
$oldTitle = (string)($old['name'] ?? $old['title'] ?? '');
$oldSku = trim((string)($old['sku'] ?? ''));
$oldStatus = (int)($old['status']['value'] ?? 3790);
$oldCategoryId = (int)($old['category']['id'] ?? $old['category_id'] ?? 0);

if ($oldCategoryId === $expectedCategoryId) {
    throw new RuntimeException('Old Basalam product is already in expected category; migration not needed.');
}
if (normTitle($oldTitle) !== normTitle($wooTitle)) {
    throw new RuntimeException('Title mismatch; refusing migration: ' . $oldTitle . ' <> ' . $wooTitle);
}

echo 'BEFORE=' . json_encode([
    'wc_product_id'=>$wcId,
    'woo_title'=>$wooTitle,
    'woo_sku'=>$wooSku,
    'old_basalam_id'=>$expectedOldBasalamId,
    'old_title'=>$oldTitle,
    'old_sku'=>$oldSku,
    'old_status'=>$oldStatus,
    'actual_category_id'=>$oldCategoryId,
    'expected_category_id'=>$expectedCategoryId,
    'expected_category_name'=>$expectedCategoryName,
    'variation_map_count'=>count($variationBackup),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

$correctCandidates = [];
$search = $client->getVendorProducts(['title'=>$wooTitle,'page'=>1,'per_page'=>100]);
if (!$search['error']) {
    $body = (array)($search['body'] ?? []);
    $items = $body['data'] ?? $body['products'] ?? $body;
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0 || $id === $expectedOldBasalamId) continue;
            $name = (string)($item['name'] ?? $item['title'] ?? '');
            if (normTitle($name) !== normTitle($wooTitle)) continue;
            $full = $client->getProduct($id, true);
            if ($full['error']) continue;
            $fb = (array)$full['body'];
            $cat = (int)($fb['category']['id'] ?? $fb['category_id'] ?? 0);
            if ($cat === $expectedCategoryId) {
                $correctCandidates[] = ['id'=>$id,'name'=>$name,'category_id'=>$cat];
            }
        }
    }
}
if ($correctCandidates) {
    throw new RuntimeException('A correct-category same-title Basalam candidate already exists; refusing duplicate create: ' . json_encode($correctCandidates, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

$legacyTitle = $oldTitle . ' - قدیمی #' . $expectedOldBasalamId;
$oldPrepared = false;
$newId = 0;

try {
    $prep = $client->updateProduct($expectedOldBasalamId, [
        'name' => $legacyTitle,
        'status' => 3790,
        'sku' => null,
    ]);
    if ($prep['error']) {
        $prep = $client->updateProduct($expectedOldBasalamId, [
            'name' => $legacyTitle,
            'status' => 3790,
            'sku' => '',
        ]);
    }
    if ($prep['error']) {
        throw new RuntimeException('Could not prepare legacy product: ' . $prep['error']);
    }

    $verifyOld = $client->getProduct($expectedOldBasalamId, true);
    if ($verifyOld['error']) throw new RuntimeException('Legacy verification failed: ' . $verifyOld['error']);
    $verifyOldBody = (array)$verifyOld['body'];
    $preparedSku = trim((string)($verifyOldBody['sku'] ?? ''));
    $preparedTitle = (string)($verifyOldBody['name'] ?? $verifyOldBody['title'] ?? '');
    if ($preparedSku !== '') {
        throw new RuntimeException('Legacy SKU was not released; refusing create.');
    }
    if (normTitle($preparedTitle) === normTitle($wooTitle)) {
        throw new RuntimeException('Legacy title was not changed; refusing duplicate create.');
    }
    $oldPrepared = true;

    echo 'LEGACY_PREPARED=' . json_encode([
        'id'=>$expectedOldBasalamId,
        'title'=>$preparedTitle,
        'sku'=>$preparedSku,
        'status'=>$verifyOldBody['status'] ?? null,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $db->beginTransaction();
    try {
        $u = $db->prepare(
            "UPDATE basalam_product_map
             SET basalam_product_id = NULL, last_wc_hash = NULL, sync_status = 'migrating',
                 sync_error = 'Pilot category migration from wrong Basalam category', last_synced_at = NULL
             WHERE wc_product_id = :wc AND basalam_product_id = :old"
        );
        $u->execute(['wc'=>$wcId,'old'=>$expectedOldBasalamId]);
        if ($u->rowCount() !== 1) throw new RuntimeException('Failed to detach old product mapping.');
        $d = $db->prepare('DELETE FROM basalam_variation_map WHERE wc_product_id = :wc');
        $d->execute(['wc'=>$wcId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $result = $sync->syncProduct($wcId, true);
    echo 'SYNC=' . json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $afterMap = $sync->getProductMap($wcId);
    $newId = (int)($afterMap['basalam_product_id'] ?? $result['basalam_product_id'] ?? 0);
    if (empty($result['success']) || $newId <= 0 || $newId === $expectedOldBasalamId) {
        throw new RuntimeException('New product creation/sync failed: ' . ($result['message'] ?? 'unknown'));
    }

    $newRes = $client->getProduct($newId, true);
    if ($newRes['error']) throw new RuntimeException('New product verification failed: ' . $newRes['error']);
    $new = (array)$newRes['body'];
    $newCategoryId = (int)($new['category']['id'] ?? $new['category_id'] ?? 0);
    $newTitle = (string)($new['name'] ?? $new['title'] ?? '');
    if ($newCategoryId !== $expectedCategoryId) {
        throw new RuntimeException("New product category mismatch: {$newCategoryId} != {$expectedCategoryId}");
    }
    if (normTitle($newTitle) !== normTitle($wooTitle)) {
        throw new RuntimeException('New product title mismatch.');
    }

    echo 'NEW_PRODUCT=' . json_encode([
        'id'=>$newId,
        'title'=>$newTitle,
        'category_id'=>$newCategoryId,
        'category_name'=>$new['category']['title'] ?? $new['category']['name'] ?? '',
        'sku'=>$new['sku'] ?? null,
        'status'=>$new['status'] ?? null,
        'variants'=>count((array)($new['variants'] ?? $new['variant'] ?? [])),
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo 'FINAL_MAP=' . json_encode($sync->getProductMap($wcId), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    $mapNow = $sync->getProductMap($wcId);
    $mappedNow = (int)($mapNow['basalam_product_id'] ?? 0);
    if ($newId <= 0 && $mappedNow > 0 && $mappedNow !== $expectedOldBasalamId) {
        $newId = $mappedNow;
    }
    if ($newId > 0 && $newId !== $expectedOldBasalamId) {
        $client->updateProduct($newId, [
            'name' => $wooTitle . ' - مهاجرت ناموفق #' . $newId,
            'status' => 3790,
            'sku' => null,
        ]);
    }

    restoreMaps($db, $currentMap, $variationBackup, $wcId);

    if ($oldPrepared) {
        $restore = $client->updateProduct($expectedOldBasalamId, [
            'name'=>$oldTitle,
            'status'=>$oldStatus,
            'sku'=>$oldSku === '' ? null : $oldSku,
        ]);
        if ($restore['error']) {
            echo 'ROLLBACK_WARNING=' . json_encode(['old_product_restore_error'=>$restore['error']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
    }

    echo 'ROLLBACK_MAP=' . json_encode($sync->getProductMap($wcId), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    throw $e;
}
