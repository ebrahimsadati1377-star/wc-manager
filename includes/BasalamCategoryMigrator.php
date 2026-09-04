<?php

class BasalamCategoryMigrator
{
    private PDO $db;
    private WooCommerceClient $wc;
    private BasalamClient $basalam;
    private BasalamSync $sync;

    public function __construct(
        ?WooCommerceClient $wc = null,
        ?BasalamClient $basalam = null,
        ?BasalamSync $sync = null
    ) {
        $this->db = Database::get();
        $this->wc = $wc ?? new WooCommerceClient();
        $this->basalam = $basalam ?? new BasalamClient();
        $this->sync = $sync ?? new BasalamSync($this->wc, $this->basalam);
        $this->ensureStorage();
    }

    private function ensureStorage(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS basalam_category_migration_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wc_product_id BIGINT UNSIGNED NOT NULL,
                old_basalam_product_id BIGINT UNSIGNED NULL,
                new_basalam_product_id BIGINT UNSIGNED NULL,
                old_category_id BIGINT UNSIGNED NULL,
                new_category_id BIGINT UNSIGNED NULL,
                status VARCHAR(30) NOT NULL,
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_wc_product_id (wc_product_id),
                KEY idx_status (status),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function dryRun(): array
    {
        if (!$this->wc->isConfigured() || !$this->basalam->isConfigured()) {
            return ['success' => false, 'message' => 'اتصال WooCommerce یا باسلام تنظیم نشده است.', 'items' => []];
        }

        $products = $this->loadWooProducts();
        $maps = $this->sync->getProductMaps(array_column($products, 'id'));
        $categoryMaps = $this->sync->getCategoryMaps();
        $items = [];
        $stats = [
            'checked' => 0,
            'correct' => 0,
            'ready' => 0,
            'needs_review' => 0,
            'read_error' => 0,
        ];

        foreach ($products as $product) {
            $wcId = (int)($product['id'] ?? 0);
            $map = $maps[$wcId] ?? null;
            $basalamId = (int)($map['basalam_product_id'] ?? 0);
            if ($wcId <= 0 || $basalamId <= 0) {
                continue;
            }
            $stats['checked']++;

            $expected = $this->resolveExpectedCategory($product, $categoryMaps);
            if (!$expected) {
                $stats['needs_review']++;
                $items[] = $this->item($product, $basalamId, 0, 0, '', 'needs_review', 'برای دسته Woo نگاشت باسلام پیدا نشد.');
                continue;
            }

            $remote = $this->basalam->getProduct($basalamId, true);
            if ($remote['error']) {
                $stats['read_error']++;
                $items[] = $this->item($product, $basalamId, 0, (int)$expected['basalam_category_id'], (string)$expected['basalam_category_name'], 'read_error', $remote['error']);
                continue;
            }

            $body = (array)$remote['body'];
            $actualId = (int)($body['category']['id'] ?? $body['category_id'] ?? 0);
            $expectedId = (int)$expected['basalam_category_id'];
            if ($actualId === $expectedId) {
                $stats['correct']++;
                continue;
            }

            $candidate = $this->findCorrectCategoryCandidate((string)($product['name'] ?? ''), $expectedId, $basalamId);
            if ($candidate) {
                $occupiedBy = $this->mappedWooForBasalam((int)$candidate['id'], $wcId);
                $stats['needs_review']++;
                $reason = $occupiedBy > 0
                    ? 'محصول هم‌نام در دسته صحیح وجود دارد و به Woo #' . $occupiedBy . ' مپ شده است.'
                    : 'محصول هم‌نام در دسته صحیح باسلام از قبل وجود دارد؛ برای جلوگیری از Duplicate نیاز به بررسی دارد.';
                $row = $this->item($product, $basalamId, $actualId, $expectedId, (string)$expected['basalam_category_name'], 'needs_review', $reason);
                $row['candidate_basalam_id'] = (int)$candidate['id'];
                $items[] = $row;
                continue;
            }

            $stats['ready']++;
            $items[] = $this->item($product, $basalamId, $actualId, $expectedId, (string)$expected['basalam_category_name'], 'ready', 'آماده مهاجرت امن است.');
        }

        return [
            'success' => true,
            'message' => 'Dry Run مهاجرت دسته‌های باسلام انجام شد.',
            'stats' => $stats,
            'items' => $items,
        ];
    }

    public function migrateBatch(int $limit = 5, array $wcProductIds = []): array
    {
        $limit = max(1, min(5, $limit));
        $dryRun = $this->dryRun();
        if (empty($dryRun['success'])) {
            return $dryRun;
        }

        $requested = array_values(array_unique(array_filter(array_map('intval', $wcProductIds))));
        $ready = array_values(array_filter($dryRun['items'], function (array $row) use ($requested) {
            if (($row['status'] ?? '') !== 'ready') return false;
            return !$requested || in_array((int)$row['wc_product_id'], $requested, true);
        }));
        $ready = array_slice($ready, 0, $limit);

        $results = [];
        $success = 0;
        $failed = 0;
        foreach ($ready as $row) {
            $result = $this->migrateProduct((int)$row['wc_product_id']);
            $results[] = $result;
            if (!empty($result['success'])) $success++; else $failed++;
        }

        return [
            'success' => $failed === 0,
            'message' => sprintf('مهاجرت دسته‌ای اجرا شد: %d موفق، %d ناموفق.', $success, $failed),
            'stats' => [
                'selected' => count($ready),
                'migrated' => $success,
                'failed' => $failed,
                'remaining_ready_before_run' => max(0, (int)($dryRun['stats']['ready'] ?? 0) - count($ready)),
            ],
            'results' => $results,
        ];
    }

    public function migrateProduct(int $wcProductId): array
    {
        $oldMap = null;
        $oldVariationMaps = [];
        $old = [];
        $oldVariantSkus = [];
        $oldParentSku = '';
        $oldTitle = '';
        $oldStatus = 3790;
        $oldId = 0;
        $newId = 0;
        $mapsChanged = false;
        $legacyPrepared = false;

        try {
            $wooRes = $this->wc->getProduct($wcProductId);
            if ($wooRes['error']) throw new RuntimeException('خواندن محصول Woo ناموفق بود: ' . $wooRes['error']);
            $product = (array)$wooRes['body'];
            if (!in_array((string)($product['type'] ?? ''), ['simple', 'variable'], true)) {
                throw new RuntimeException('نوع محصول برای مهاجرت پشتیبانی نمی‌شود.');
            }

            $oldMap = $this->sync->getProductMap($wcProductId);
            $oldId = (int)($oldMap['basalam_product_id'] ?? 0);
            if ($oldId <= 0) throw new RuntimeException('محصول به باسلام مپ نشده است.');

            $categoryMaps = $this->sync->getCategoryMaps();
            $expected = $this->resolveExpectedCategory($product, $categoryMaps);
            if (!$expected) throw new RuntimeException('دسته صحیح باسلام قابل تشخیص نیست.');
            $expectedId = (int)$expected['basalam_category_id'];
            $expectedName = (string)$expected['basalam_category_name'];

            $oldRes = $this->basalam->getProduct($oldId, true);
            if ($oldRes['error']) throw new RuntimeException('خواندن محصول فعلی باسلام ناموفق بود: ' . $oldRes['error']);
            $old = (array)$oldRes['body'];
            $oldCategoryId = (int)($old['category']['id'] ?? $old['category_id'] ?? 0);
            if ($oldCategoryId === $expectedId) {
                return ['success' => true, 'skipped' => true, 'wc_product_id' => $wcProductId, 'basalam_product_id' => $oldId, 'message' => 'محصول از قبل در دسته صحیح است.'];
            }

            $wooTitle = trim((string)($product['name'] ?? ''));
            if ($wooTitle === '') throw new RuntimeException('نام محصول Woo خالی است.');

            $candidate = $this->findCorrectCategoryCandidate($wooTitle, $expectedId, $oldId);
            if ($candidate) {
                throw new RuntimeException('محصول هم‌نام در دسته صحیح باسلام موجود است (#' . (int)$candidate['id'] . ')؛ مهاجرت خودکار متوقف شد.');
            }

            $type = (string)$product['type'];
            $variations = [];
            if ($type === 'variable') {
                $vr = $this->wc->getVariations($wcProductId, ['per_page' => 100]);
                if ($vr['error']) throw new RuntimeException('خواندن variationهای Woo ناموفق بود: ' . $vr['error']);
                $variations = (array)$vr['body'];
            }

            $stmt = $this->db->prepare('SELECT * FROM basalam_variation_map WHERE wc_product_id=:wc ORDER BY wc_variation_id');
            $stmt->execute(['wc' => $wcProductId]);
            $oldVariationMaps = $stmt->fetchAll();

            $oldTitle = (string)($old['name'] ?? $old['title'] ?? '');
            $oldParentSku = trim((string)($old['sku'] ?? ''));
            $oldStatus = (int)($old['status']['value'] ?? $old['status'] ?? 3790);
            foreach ((array)($old['variants'] ?? $old['variant'] ?? []) as $variant) {
                if (!is_array($variant)) continue;
                $vid = (int)($variant['id'] ?? 0);
                if ($vid > 0) $oldVariantSkus[$vid] = trim((string)($variant['sku'] ?? ''));
            }

            $legacyTitle = $wooTitle . ' - قدیمی #' . $oldId;
            $prep = $this->basalam->updateProduct($oldId, ['name' => $legacyTitle, 'status' => 3790, 'sku' => null]);
            if ($prep['error']) throw new RuntimeException('غیرفعال‌سازی محصول قدیمی ناموفق بود: ' . $prep['error']);
            foreach ($oldVariantSkus as $vid => $sku) {
                if ($sku !== '') $this->setVariantSku($oldId, $vid, null);
            }
            $legacyPrepared = true;

            // Basalam product updates can be eventually consistent. Poll the full product
            // until the legacy title/status/SKUs are visibly safe before creating a replacement.
            $verifyBody = [];
            $legacySafe = false;
            $verifyError = null;
            for ($attempt = 0; $attempt < 8; $attempt++) {
                if ($attempt > 0) usleep(750000);
                $verify = $this->basalam->getProduct($oldId, true);
                if ($verify['error']) {
                    $verifyError = $verify['error'];
                    continue;
                }
                $verifyBody = (array)$verify['body'];
                $verifyTitle = (string)($verifyBody['name'] ?? $verifyBody['title'] ?? '');
                $verifyStatus = (int)($verifyBody['status']['value'] ?? $verifyBody['status'] ?? 0);
                $parentSkuReleased = trim((string)($verifyBody['sku'] ?? '')) === '';
                $variantSkusReleased = true;
                foreach ((array)($verifyBody['variants'] ?? $verifyBody['variant'] ?? []) as $variant) {
                    if (trim((string)($variant['sku'] ?? '')) !== '') {
                        $variantSkusReleased = false;
                        break;
                    }
                }
                if (
                    $verifyStatus === 3790
                    && $this->normalizeTitle($verifyTitle) !== $this->normalizeTitle($wooTitle)
                    && $parentSkuReleased
                    && $variantSkusReleased
                ) {
                    $legacySafe = true;
                    break;
                }
            }
            if (!$legacySafe) {
                $suffix = $verifyError ? ' آخرین خطا: ' . $verifyError : '';
                throw new RuntimeException('محصول قدیمی به حالت امن منتقل نشد؛ ساخت محصول جدید متوقف شد.' . $suffix);
            }

            $payload = $this->buildCreatePayload($product, $variations, $expectedId);
            $create = $this->basalam->createProduct($payload);
            if ($create['error']) throw new RuntimeException('ساخت محصول در دسته صحیح ناموفق بود: ' . $create['error']);
            $createBody = (array)$create['body'];
            $newId = (int)($createBody['id'] ?? 0);
            if ($newId <= 0 || $newId === $oldId) throw new RuntimeException('باسلام شناسه معتبر برای محصول جدید برنگرداند.');

            $newRead = $this->basalam->getProduct($newId, true);
            if ($newRead['error']) throw new RuntimeException('خواندن محصول جدید ناموفق بود: ' . $newRead['error']);
            $newBody = (array)$newRead['body'];
            $newCategoryId = (int)($newBody['category']['id'] ?? $newBody['category_id'] ?? 0);
            if ($newCategoryId !== $expectedId) throw new RuntimeException('محصول جدید در دسته مورد انتظار ساخته نشد.');

            $this->db->beginTransaction();
            try {
                $this->db->prepare('DELETE FROM basalam_variation_map WHERE wc_product_id=:wc')->execute(['wc' => $wcProductId]);
                $u = $this->db->prepare(
                    "UPDATE basalam_product_map
                     SET basalam_product_id=:newid,last_wc_hash=NULL,sync_status='matched',
                         sync_error='Migrated from wrong Basalam category',last_synced_at=NULL
                     WHERE wc_product_id=:wc AND basalam_product_id=:oldid"
                );
                $u->execute(['newid' => $newId, 'wc' => $wcProductId, 'oldid' => $oldId]);
                if ($u->rowCount() !== 1) throw new RuntimeException('انتقال مپ محصول ناموفق بود.');
                $this->db->commit();
                $mapsChanged = true;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }

            $capture = $this->captureCreatedVariations($wcProductId, $newId, $variations, (array)($createBody['variants'] ?? []));
            $syncResult = $this->sync->syncProduct($wcProductId, true);
            if (empty($syncResult['success'])) throw new RuntimeException('سینک بعد از مهاجرت ناموفق بود: ' . ($syncResult['message'] ?? 'unknown'));

            $final = $this->basalam->getProduct($newId, true);
            if ($final['error']) throw new RuntimeException('تأیید نهایی محصول جدید ناموفق بود: ' . $final['error']);
            $finalBody = (array)$final['body'];
            $finalCategoryId = (int)($finalBody['category']['id'] ?? $finalBody['category_id'] ?? 0);
            if ($finalCategoryId !== $expectedId) throw new RuntimeException('دسته محصول جدید بعد از سینک تغییر غیرمنتظره کرد.');

            $message = 'مهاجرت امن از Basalam #' . $oldId . ' به #' . $newId . ' انجام شد.';
            $this->logMigration($wcProductId, $oldId, $newId, $oldCategoryId, $expectedId, 'success', $message);
            logActivity('basalam_category_migrate', 'product:' . $wcProductId, $message);

            return [
                'success' => true,
                'wc_product_id' => $wcProductId,
                'woo_name' => $wooTitle,
                'old_basalam_product_id' => $oldId,
                'new_basalam_product_id' => $newId,
                'old_category_id' => $oldCategoryId,
                'new_category_id' => $expectedId,
                'new_category_name' => $expectedName,
                'variation_warnings' => $capture['warnings'] ?? [],
                'sync_warnings' => $syncResult['warnings'] ?? [],
                'message' => $message,
            ];
        } catch (Throwable $e) {
            if ($mapsChanged && is_array($oldMap)) {
                $this->restoreMaps($wcProductId, $oldMap, $oldVariationMaps);
            }

            if ($newId > 0) {
                $this->neutralizeProduct($newId, 'مهاجرت ناموفق #' . $newId);
            }

            if ($legacyPrepared && $oldId > 0) {
                $this->restoreLegacyProduct($oldId, $oldTitle, $oldStatus, $oldParentSku, $oldVariantSkus);
            }

            $oldCategoryId = (int)($old['category']['id'] ?? $old['category_id'] ?? 0);
            $expectedId = isset($expected) && is_array($expected) ? (int)($expected['basalam_category_id'] ?? 0) : 0;
            $this->logMigration($wcProductId, $oldId, $newId, $oldCategoryId, $expectedId, 'failed', $e->getMessage());
            error_log('[wc-manager] Basalam category migration failed Woo #' . $wcProductId . ': ' . $e->getMessage());

            return [
                'success' => false,
                'wc_product_id' => $wcProductId,
                'old_basalam_product_id' => $oldId,
                'new_basalam_product_id' => $newId,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function loadWooProducts(): array
    {
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            $res = $this->wc->getProducts(['page' => $page, 'per_page' => 100, 'orderby' => 'id', 'order' => 'asc']);
            if ($res['error']) throw new RuntimeException('خواندن لیست Woo ناموفق بود: ' . $res['error']);
            $rows = is_array($res['body']) ? $res['body'] : [];
            $all = array_merge($all, $rows);
            if (count($rows) < 100) break;
        }
        return $all;
    }

    private function resolveExpectedCategory(array $product, array $categoryMaps): ?array
    {
        foreach (($product['categories'] ?? []) as $category) {
            $id = (int)($category['id'] ?? 0);
            if ($id > 0 && isset($categoryMaps[$id])) return $categoryMaps[$id];
        }
        return null;
    }

    private function item(array $product, int $basalamId, int $actualId, int $expectedId, string $expectedName, string $status, string $reason): array
    {
        return [
            'wc_product_id' => (int)($product['id'] ?? 0),
            'woo_name' => (string)($product['name'] ?? ''),
            'woo_type' => (string)($product['type'] ?? ''),
            'woo_sku' => (string)($product['sku'] ?? ''),
            'basalam_product_id' => $basalamId,
            'actual_category_id' => $actualId,
            'expected_category_id' => $expectedId,
            'expected_category_name' => $expectedName,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function findCorrectCategoryCandidate(string $title, int $expectedCategoryId, int $excludeId): ?array
    {
        if (trim($title) === '') return null;
        $res = $this->basalam->getVendorProducts(['title' => $title, 'page' => 1, 'per_page' => 100]);
        if ($res['error']) return null;
        $body = (array)($res['body'] ?? []);
        $items = $body['data'] ?? $body['products'] ?? $body;
        if (!is_array($items)) return null;
        $needle = $this->normalizeTitle($title);
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0 || $id === $excludeId) continue;
            $name = (string)($item['name'] ?? $item['title'] ?? '');
            if ($this->normalizeTitle($name) !== $needle) continue;
            $full = $this->basalam->getProduct($id, true);
            if ($full['error']) continue;
            $fullBody = (array)$full['body'];
            $categoryId = (int)($fullBody['category']['id'] ?? $fullBody['category_id'] ?? 0);
            if ($categoryId === $expectedCategoryId) return ['id' => $id, 'name' => $name];
        }
        return null;
    }

    private function mappedWooForBasalam(int $basalamProductId, int $excludeWooId): int
    {
        $stmt = $this->db->prepare('SELECT wc_product_id FROM basalam_product_map WHERE basalam_product_id=:id AND wc_product_id<>:wc LIMIT 1');
        $stmt->execute(['id' => $basalamProductId, 'wc' => $excludeWooId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function buildCreatePayload(array $product, array $variations, int $categoryId): array
    {
        $build = new ReflectionMethod(BasalamSync::class, 'buildProductPayload');
        $build->setAccessible(true);
        $payload = $build->invoke($this->sync, $product, $variations, $categoryId, true);

        if (in_array((string)getSetting('basalam_sync_attributes', '1'), ['1', 'true', 'yes', 'on'], true)) {
            $mapper = new BasalamAttributeMapper($this->basalam);
            $attrs = $mapper->build($product, $variations, $categoryId, null);
            if (!empty($attrs['attributes'])) $payload['product_attribute'] = array_values($attrs['attributes']);
        }

        if (in_array((string)getSetting('basalam_sync_images', '1'), ['1', 'true', 'yes', 'on'], true)) {
            $warnings = [];
            $upload = new ReflectionMethod(BasalamSync::class, 'uploadWooImages');
            $upload->setAccessible(true);
            $args = [$product, &$warnings];
            $ids = $upload->invokeArgs($this->sync, $args);
            if ($ids) {
                $payload['photo'] = array_shift($ids);
                if ($ids) $payload['photos'] = $ids;
            }
        }
        return $payload;
    }

    private function captureCreatedVariations(int $wcProductId, int $basalamProductId, array $wooVariations, array $basalamVariants): array
    {
        $capture = new ReflectionMethod(BasalamSync::class, 'captureCreatedVariationMaps');
        $capture->setAccessible(true);
        return (array)$capture->invoke($this->sync, $wcProductId, $basalamProductId, $wooVariations, $basalamVariants);
    }

    private function setVariantSku(int $productId, int $variationId, ?string $sku): void
    {
        $payloads = ($sku === null || $sku === '') ? [['sku' => null], ['sku' => '']] : [['sku' => $sku]];
        $lastError = null;
        foreach ($payloads as $payload) {
            $res = $this->basalam->updateProductVariation($productId, $variationId, $payload);
            if (!$res['error']) return;
            $lastError = $res['error'];
        }
        throw new RuntimeException('به‌روزرسانی SKU تنوع باسلام #' . $variationId . ' ناموفق بود: ' . $lastError);
    }

    private function neutralizeProduct(int $productId, string $suffix): void
    {
        $read = $this->basalam->getProduct($productId, true);
        if (!$read['error']) {
            foreach ((array)($read['body']['variants'] ?? $read['body']['variant'] ?? []) as $variant) {
                $vid = (int)($variant['id'] ?? 0);
                if ($vid > 0 && trim((string)($variant['sku'] ?? '')) !== '') {
                    try { $this->setVariantSku($productId, $vid, null); } catch (Throwable $ignored) {}
                }
            }
        }
        $this->basalam->updateProduct($productId, ['name' => $suffix, 'status' => 3790, 'sku' => null]);
    }

    private function restoreLegacyProduct(int $oldId, string $title, int $status, string $parentSku, array $variantSkus): void
    {
        $this->basalam->updateProduct($oldId, [
            'name' => $title,
            'status' => $status,
            'sku' => $parentSku === '' ? null : $parentSku,
        ]);
        foreach ($variantSkus as $vid => $sku) {
            if ($sku !== '') {
                try { $this->setVariantSku($oldId, (int)$vid, $sku); } catch (Throwable $ignored) {}
            }
        }
    }

    private function restoreMaps(int $wcProductId, array $productMap, array $variationMaps): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM basalam_variation_map WHERE wc_product_id=:wc')->execute(['wc' => $wcProductId]);
            $this->db->prepare('DELETE FROM basalam_product_map WHERE wc_product_id=:wc')->execute(['wc' => $wcProductId]);
            $p = $this->db->prepare(
                'INSERT INTO basalam_product_map
                 (wc_product_id,basalam_product_id,last_wc_hash,sync_status,sync_error,last_synced_at,created_at,updated_at)
                 VALUES (:wc,:bp,:hash,:status,:error,:last_sync,:created,:updated)'
            );
            $p->execute([
                'wc' => $productMap['wc_product_id'], 'bp' => $productMap['basalam_product_id'],
                'hash' => $productMap['last_wc_hash'], 'status' => $productMap['sync_status'],
                'error' => $productMap['sync_error'], 'last_sync' => $productMap['last_synced_at'],
                'created' => $productMap['created_at'], 'updated' => $productMap['updated_at'],
            ]);
            if ($variationMaps) {
                $v = $this->db->prepare(
                    'INSERT INTO basalam_variation_map
                     (wc_variation_id,wc_product_id,basalam_product_id,basalam_variation_id,sku,sync_status,sync_error,last_synced_at,created_at,updated_at)
                     VALUES (:wcv,:wcp,:bp,:bv,:sku,:status,:error,:last_sync,:created,:updated)'
                );
                foreach ($variationMaps as $r) {
                    $v->execute([
                        'wcv' => $r['wc_variation_id'], 'wcp' => $r['wc_product_id'], 'bp' => $r['basalam_product_id'],
                        'bv' => $r['basalam_variation_id'], 'sku' => $r['sku'], 'status' => $r['sync_status'],
                        'error' => $r['sync_error'], 'last_sync' => $r['last_synced_at'],
                        'created' => $r['created_at'], 'updated' => $r['updated_at'],
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function normalizeTitle(string $title): string
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = str_replace(["\u{200c}", "\u{200d}", "\u{200e}", "\u{200f}", 'ي', 'ى', 'ك', 'ة', 'ۀ'], ['', '', '', '', 'ی', 'ی', 'ک', 'ه', 'ه'], $title);
        $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
        return preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
    }

    private function logMigration(int $wcId, int $oldId, int $newId, int $oldCategoryId, int $newCategoryId, string $status, string $message): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO basalam_category_migration_log
             (wc_product_id,old_basalam_product_id,new_basalam_product_id,old_category_id,new_category_id,status,message)
             VALUES (:wc,:old,:new,:oldcat,:newcat,:status,:message)'
        );
        $stmt->execute([
            'wc' => $wcId,
            'old' => $oldId > 0 ? $oldId : null,
            'new' => $newId > 0 ? $newId : null,
            'oldcat' => $oldCategoryId > 0 ? $oldCategoryId : null,
            'newcat' => $newCategoryId > 0 ? $newCategoryId : null,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
