<?php

class BasalamSync
{
    private WooCommerceClient $wc;
    private BasalamClient $basalam;
    private PDO $db;

    public function __construct(?WooCommerceClient $wc = null, ?BasalamClient $basalam = null)
    {
        $this->wc = $wc ?? new WooCommerceClient();
        $this->basalam = $basalam ?? new BasalamClient();
        $this->db = Database::get();
        $this->ensureStorage();
    }

    public function ensureStorage(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS basalam_product_map (
                wc_product_id BIGINT UNSIGNED NOT NULL,
                basalam_product_id BIGINT UNSIGNED NULL,
                last_wc_hash CHAR(64) NULL,
                sync_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                sync_error TEXT NULL,
                last_synced_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (wc_product_id),
                UNIQUE KEY uq_basalam_product_id (basalam_product_id),
                KEY idx_sync_status (sync_status),
                KEY idx_last_synced_at (last_synced_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS basalam_variation_map (
                wc_variation_id BIGINT UNSIGNED NOT NULL,
                wc_product_id BIGINT UNSIGNED NOT NULL,
                basalam_product_id BIGINT UNSIGNED NULL,
                basalam_variation_id BIGINT UNSIGNED NULL,
                sku VARCHAR(190) NULL,
                sync_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                sync_error TEXT NULL,
                last_synced_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (wc_variation_id),
                UNIQUE KEY uq_basalam_variation_id (basalam_variation_id),
                KEY idx_wc_product_id (wc_product_id),
                KEY idx_basalam_product_id (basalam_product_id),
                KEY idx_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS basalam_category_map (
                wc_category_id BIGINT UNSIGNED NOT NULL,
                basalam_category_id BIGINT UNSIGNED NOT NULL,
                basalam_category_name VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (wc_category_id),
                KEY idx_basalam_category_id (basalam_category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function isConfigured(): bool
    {
        return $this->wc->isConfigured() && $this->basalam->isConfigured();
    }

    public function getProductMap(int $wcProductId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM basalam_product_map WHERE wc_product_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $wcProductId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function getProductMaps(array $wcProductIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $wcProductIds))));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM basalam_product_map WHERE wc_product_id IN ($placeholders)"
        );
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['wc_product_id']] = $row;
        }
        return $result;
    }

    public function getCategoryMaps(): array
    {
        $rows = $this->db
            ->query('SELECT * FROM basalam_category_map ORDER BY wc_category_id ASC')
            ->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['wc_category_id']] = $row;
        }
        return $result;
    }

    public function replaceCategoryMaps(array $maps): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM basalam_category_map');
            $stmt = $this->db->prepare(
                'INSERT INTO basalam_category_map
                    (wc_category_id, basalam_category_id, basalam_category_name)
                 VALUES (:wc, :basalam, :name)'
            );

            foreach ($maps as $map) {
                $wcId = (int)($map['wc_category_id'] ?? 0);
                $basalamId = (int)($map['basalam_category_id'] ?? 0);
                if ($wcId <= 0 || $basalamId <= 0) {
                    continue;
                }

                $stmt->execute([
                    'wc' => $wcId,
                    'basalam' => $basalamId,
                    'name' => (string)($map['basalam_category_name'] ?? ''),
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function syncProduct(int $wcProductId, bool $force = false): array
    {
        if (!$this->wc->isConfigured()) {
            return $this->syncFailure($wcProductId, 'اتصال ووکامرس تنظیم نشده است.');
        }
        if (!$this->basalam->isConfigured()) {
            return $this->syncFailure($wcProductId, 'اتصال باسلام تنظیم نشده است.');
        }

        $productRes = $this->wc->getProduct($wcProductId);
        if ($productRes['error']) {
            return $this->syncFailure(
                $wcProductId,
                'خواندن محصول ووکامرس ناموفق بود: ' . $productRes['error']
            );
        }

        $product = $productRes['body'];
        $type = (string)($product['type'] ?? 'simple');
        if (!in_array($type, ['simple', 'variable'], true)) {
            return $this->syncFailure(
                $wcProductId,
                'فعلاً فقط محصولات simple و variable قابل سینک هستند.'
            );
        }

        $categoryMap = $this->resolveBasalamCategory($product);
        if ($categoryMap === null) {
            return $this->syncFailure(
                $wcProductId,
                'برای هیچ‌کدام از دسته‌بندی‌های این محصول، نگاشت باسلام تعریف نشده است.'
            );
        }

        $variations = [];
        if ($type === 'variable') {
            $variationRes = $this->wc->getVariations($wcProductId, ['per_page' => 100]);
            if ($variationRes['error']) {
                return $this->syncFailure(
                    $wcProductId,
                    'خواندن تنوع‌های ووکامرس ناموفق بود: ' . $variationRes['error']
                );
            }
            $variations = $variationRes['body'];
        }

        $hash = $this->computeWooHash($product, $variations, $categoryMap);
        $map = $this->getProductMap($wcProductId);

        if (
            !$force
            && $map
            && ($map['sync_status'] ?? '') === 'synced'
            && hash_equals((string)($map['last_wc_hash'] ?? ''), $hash)
        ) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'محصول از آخرین سینک تغییری نکرده است.',
                'wc_product_id' => $wcProductId,
                'basalam_product_id' => (int)($map['basalam_product_id'] ?? 0),
                'warnings' => [],
            ];
        }

        $creating = !$map || (int)($map['basalam_product_id'] ?? 0) <= 0;
        $warnings = [];
        $attributeCategoryId = (int)$categoryMap['basalam_category_id'];

        // Existing Basalam products cannot always change category. Build product
        // attributes against the product's real Basalam category, not a newer Woo
        // mapping, otherwise Basalam rejects the update with product_attribute 422.
        if (!$creating) {
            $existingBasalam = $this->basalam->getProduct((int)$map['basalam_product_id'], true);
            if (!$existingBasalam['error']) {
                $actualCategoryId = (int)(
                    $existingBasalam['body']['category']['id']
                    ?? $existingBasalam['body']['category_id']
                    ?? 0
                );
                if ($actualCategoryId > 0) {
                    if ($actualCategoryId !== $attributeCategoryId) {
                        $warnings[] = sprintf(
                            'دسته محصول باسلام #%d با دسته نگاشت‌شده Woo متفاوت است؛ ویژگی‌ها بر اساس دسته فعلی باسلام سینک شدند و دسته به‌صورت خودکار تغییر نکرد.',
                            $actualCategoryId
                        );
                    }
                    $attributeCategoryId = $actualCategoryId;
                }
            }
        }

        $payload = $this->buildProductPayload(
            $product,
            $variations,
            (int)$categoryMap['basalam_category_id'],
            $creating
        );

        if ($this->settingBool('basalam_sync_attributes', true)) {
            $attributeMapper = new BasalamAttributeMapper($this->basalam);
            $attributeResult = $attributeMapper->build(
                $product,
                $variations,
                $attributeCategoryId,
                $creating ? null : (int)($map['basalam_product_id'] ?? 0)
            );
            if (!empty($attributeResult['attributes'])) {
                $payload['product_attribute'] = array_values($attributeResult['attributes']);
            }
            if (!empty($attributeResult['warnings'])) {
                $warnings = array_merge($warnings, $attributeResult['warnings']);
            }
        }

        if (($creating || $force) && $this->settingBool('basalam_sync_images', true)) {
            $imageIds = $this->uploadWooImages($product, $warnings);
            if ($imageIds) {
                $payload['photo'] = array_shift($imageIds);
                if ($imageIds) {
                    $payload['photos'] = $imageIds;
                }
            }
        }

        if ($creating) {
            $basalamRes = $this->basalam->createProduct($payload);
        } else {
            $basalamProductId = (int)$map['basalam_product_id'];
            $parentPayload = $payload;
            unset($parentPayload['variants']);
            $basalamRes = $this->basalam->updateProduct($basalamProductId, $parentPayload);
        }

        if ($basalamRes['error']) {
            return $this->syncFailure(
                $wcProductId,
                'سینک محصول با باسلام ناموفق بود: ' . $basalamRes['error'],
                (int)($map['basalam_product_id'] ?? 0)
            );
        }

        $basalamBody = $basalamRes['body'];
        $basalamProductId = (int)(
            $basalamBody['id']
            ?? ($map['basalam_product_id'] ?? 0)
        );

        if ($basalamProductId <= 0) {
            return $this->syncFailure(
                $wcProductId,
                'باسلام پاسخ موفق داد اما شناسه محصول در پاسخ موجود نبود.'
            );
        }

        $this->saveProductMap($wcProductId, $basalamProductId, $hash, 'synced', null);

        if ($type === 'variable') {
            $variantResult = $creating
                ? $this->captureCreatedVariationMaps(
                    $wcProductId,
                    $basalamProductId,
                    $variations,
                    $basalamBody['variants'] ?? []
                )
                : $this->syncExistingVariations(
                    $wcProductId,
                    $basalamProductId,
                    $variations
                );

            if (!empty($variantResult['warnings'])) {
                $warnings = array_merge($warnings, $variantResult['warnings']);
            }
        }

        $status = $warnings ? 'partial' : 'synced';
        $errorText = $warnings ? implode("\n", $warnings) : null;
        $this->saveProductMap($wcProductId, $basalamProductId, $hash, $status, $errorText);

        logActivity(
            'basalam_sync_product',
            'product:' . $wcProductId,
            sprintf(
                'Woo #%d -> Basalam #%d (%s)%s',
                $wcProductId,
                $basalamProductId,
                $creating ? 'create' : 'update',
                $warnings ? ' | warnings: ' . implode(' | ', $warnings) : ''
            )
        );

        return [
            'success' => true,
            'skipped' => false,
            'message' => $warnings
                ? 'محصول سینک شد، اما بعضی بخش‌ها نیاز به بررسی دارند.'
                : 'محصول با باسلام سینک شد.',
            'wc_product_id' => $wcProductId,
            'basalam_product_id' => $basalamProductId,
            'warnings' => $warnings,
        ];
    }

    private function buildProductPayload(
        array $product,
        array $variations,
        int $basalamCategoryId,
        bool $includeVariants
    ): array {
        $priceMultiplier = max(0.0001, (float)getSetting('basalam_price_multiplier', '1'));
        $weightMultiplier = max(0.0001, (float)getSetting('basalam_weight_multiplier', '1000'));
        $preparationDays = max(1, (int)getSetting('basalam_preparation_days', '1'));

        $payload = [
            'name' => $this->plainText((string)($product['name'] ?? '')),
            'category_id' => $basalamCategoryId,
            'status' => ($product['status'] ?? '') === 'publish' ? 2976 : 3790,
            'preparation_days' => $preparationDays,
            'is_wholesale' => false,
            'virtual' => (bool)($product['virtual'] ?? false),
        ];

        $brief = $this->plainText((string)($product['short_description'] ?? ''));
        if ($brief !== '') {
            $payload['brief'] = $this->limitText($brief, 500);
        }

        $description = $this->plainText((string)($product['description'] ?? ''));
        if ($description !== '') {
            $payload['description'] = $description;
        }

        $tags = [];
        foreach (($product['tags'] ?? []) as $tag) {
            $name = trim((string)($tag['name'] ?? ''));
            if ($name !== '') {
                $tags[] = $name;
            }
        }
        if ($tags) {
            $payload['keywords'] = array_values(array_unique($tags));
        }

        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku !== '') {
            $payload['sku'] = $sku;
        }

        $weight = (float)($product['weight'] ?? 0);
        $defaultPackageWeight = max(0, (int)getSetting('basalam_default_package_weight', '0'));
        if ($weight > 0) {
            $weightInBasalamUnit = max(1, (int)round($weight * $weightMultiplier));
            $payload['weight'] = $weightInBasalamUnit;
            $payload['package_weight'] = max($weightInBasalamUnit + 1, $defaultPackageWeight);
        } elseif ($defaultPackageWeight > 1) {
            // Basalam requires product weight and package weight, with package weight strictly greater.
            // Preserve the configured packaged weight and use the closest valid fallback for missing Woo weight.
            $payload['weight'] = $defaultPackageWeight - 1;
            $payload['package_weight'] = $defaultPackageWeight;
        }

        if (($product['type'] ?? 'simple') === 'variable') {
            if ($includeVariants) {
                $variationSkuCounts = [];
                foreach ($variations as $variation) {
                    $variationSku = trim((string)($variation['sku'] ?? ''));
                    if ($variationSku !== '') {
                        $variationSkuCounts[$variationSku] = ($variationSkuCounts[$variationSku] ?? 0) + 1;
                    }
                }

                $payload['variants'] = array_values(array_filter(array_map(
                    function(array $variation) use ($priceMultiplier, $sku, $variationSkuCounts) {
                        $variantPayload = $this->buildVariantPayload($variation, $priceMultiplier);
                        if ($variantPayload === null) {
                            return null;
                        }

                        $variationSku = trim((string)($variantPayload['sku'] ?? ''));
                        if (
                            $variationSku !== ''
                            && ($variationSku === $sku || ($variationSkuCounts[$variationSku] ?? 0) > 1)
                        ) {
                            unset($variantPayload['sku']);
                        }
                        return $variantPayload;
                    },
                    $variations
                )));
            }
        } else {
            $payload['primary_price'] = $this->wooPriceToBasalam($product, $priceMultiplier);
            $payload['stock'] = $this->wooStock($product);
        }

        return $payload;
    }

    private function buildVariantPayload(array $variation, float $priceMultiplier): ?array
    {
        $properties = [];
        foreach (($variation['attributes'] ?? []) as $attribute) {
            $name = trim((string)($attribute['name'] ?? ''));
            $value = trim((string)($attribute['option'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $properties[] = ['property' => $name, 'value' => $value];
        }

        if (!$properties) {
            return null;
        }

        $payload = [
            'primary_price' => $this->wooPriceToBasalam($variation, $priceMultiplier),
            'stock' => $this->wooStock($variation),
            'properties' => $properties,
        ];

        $sku = trim((string)($variation['sku'] ?? ''));
        if ($sku !== '') {
            $payload['sku'] = $sku;
        }

        return $payload;
    }

    private function wooPriceToBasalam(array $item, float $multiplier): int
    {
        $price = $item['price'] ?? '';
        if ($price === '' || $price === null) {
            $price = $item['regular_price'] ?? 0;
        }
        return max(0, (int)round((float)$price * $multiplier));
    }

    private function wooStock(array $item): int
    {
        if (!empty($item['manage_stock'])) {
            return max(0, (int)($item['stock_quantity'] ?? 0));
        }

        if (($item['stock_status'] ?? '') === 'instock') {
            return max(1, (int)getSetting('basalam_unmanaged_stock', '1'));
        }

        return 0;
    }

    private function resolveBasalamCategory(array $product): ?array
    {
        $categories = $product['categories'] ?? [];
        if (!$categories) {
            return null;
        }

        $ids = [];
        foreach ($categories as $category) {
            $id = (int)($category['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM basalam_category_map
             WHERE wc_category_id IN ($placeholders)
             ORDER BY FIELD(wc_category_id, " . implode(',', array_map('intval', $ids)) . ")
             LIMIT 1"
        );
        $stmt->execute($ids);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function uploadWooImages(array $product, array &$warnings): array
    {
        $maxImages = min(10, max(1, (int)getSetting('basalam_max_images', '6')));
        $ids = [];
        $safeCropTopPercent = 0;

        // Once a product has been remediated for Basalam image moderation, keep
        // using the safe Basalam-only crop on future force-syncs. Woo originals
        // remain untouched and the policy can be reset by removing the job row.
        $wcProductId = (int)($product['id'] ?? 0);
        if ($wcProductId > 0) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT crop_top_percent FROM basalam_safe_image_jobs
                     WHERE wc_product_id = :id AND last_status = 'submitted' LIMIT 1"
                );
                $stmt->execute(['id' => $wcProductId]);
                $safeJob = $stmt->fetch();
                if (is_array($safeJob)) {
                    $safeCropTopPercent = max(15, min(40, (int)($safeJob['crop_top_percent'] ?? 28)));
                }
            } catch (Throwable $e) {
                // Table may not exist yet on older installs; fall back to normal images.
                $safeCropTopPercent = 0;
            }
        }

        foreach (array_slice($product['images'] ?? [], 0, $maxImages) as $image) {
            $url = trim((string)($image['src'] ?? ''));
            if ($url === '') {
                continue;
            }

            $upload = $safeCropTopPercent > 0
                ? BasalamSafeImageProcessor::upload($this->basalam, $url, $safeCropTopPercent)
                : BasalamImageProcessor::upload($this->basalam, $url);
            if ($upload['error']) {
                $warnings[] = 'آپلود یک تصویر ناموفق بود: ' . $upload['error'];
                continue;
            }

            $id = (int)($upload['body']['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            } else {
                $warnings[] = 'باسلام برای یک تصویر آپلودشده شناسه فایل برنگرداند.';
            }
        }

        return $ids;
    }

    private function captureCreatedVariationMaps(
        int $wcProductId,
        int $basalamProductId,
        array $wooVariations,
        array $basalamVariants
    ): array {
        $warnings = [];
        $used = [];

        foreach ($wooVariations as $wooVariation) {
            $matchIndex = $this->findVariantMatchIndex($wooVariation, $basalamVariants, $used);
            if ($matchIndex === null) {
                $warnings[] = 'تنوع Woo #' . (int)$wooVariation['id'] . ' در پاسخ باسلام match نشد.';
                $this->saveVariationMap(
                    (int)$wooVariation['id'], $wcProductId, $basalamProductId, null,
                    (string)($wooVariation['sku'] ?? ''), 'unmatched',
                    'Variation not found in Basalam create response'
                );
                continue;
            }

            $used[$matchIndex] = true;
            $basalamVariant = $basalamVariants[$matchIndex];
            $this->saveVariationMap(
                (int)$wooVariation['id'], $wcProductId, $basalamProductId,
                (int)($basalamVariant['id'] ?? 0) ?: null,
                (string)($wooVariation['sku'] ?? ''), 'synced', null
            );
        }

        return ['warnings' => $warnings];
    }

    private function syncExistingVariations(
        int $wcProductId,
        int $basalamProductId,
        array $wooVariations
    ): array {
        $warnings = [];
        $productRes = $this->basalam->getProduct($basalamProductId, true);
        $basalamVariants = !$productRes['error']
            ? (array)($productRes['body']['variants'] ?? [])
            : [];

        $existingMaps = $this->getVariationMapsByProduct($wcProductId);
        $used = [];
        $basalamParentSku = trim((string)($productRes['body']['sku'] ?? ''));

        // Reserve Basalam variants that are already mapped so a new/unmapped Woo
        // variation can never be attached to an existing mapping by mistake.
        foreach ($existingMaps as $existingMap) {
            $mappedVariationId = (int)($existingMap['basalam_variation_id'] ?? 0);
            if ($mappedVariationId <= 0) {
                continue;
            }
            foreach ($basalamVariants as $index => $variant) {
                if ((int)($variant['id'] ?? 0) === $mappedVariationId) {
                    $used[$index] = true;
                    break;
                }
            }
        }

        // A duplicated Woo variation SKU is not a safe identifier and should not
        // be pushed to every Basalam variation.
        $skuCounts = [];
        foreach ($wooVariations as $variation) {
            $variationSku = trim((string)($variation['sku'] ?? ''));
            if ($variationSku !== '') {
                $skuCounts[$variationSku] = ($skuCounts[$variationSku] ?? 0) + 1;
            }
        }

        foreach ($wooVariations as $wooVariation) {
            $wcVariationId = (int)($wooVariation['id'] ?? 0);
            $map = $existingMaps[$wcVariationId] ?? null;
            $basalamVariationId = (int)($map['basalam_variation_id'] ?? 0);

            if ($basalamVariationId <= 0 && $basalamVariants) {
                $matchIndex = $this->findVariantMatchIndex($wooVariation, $basalamVariants, $used);
                if ($matchIndex !== null) {
                    $used[$matchIndex] = true;
                    $basalamVariationId = (int)($basalamVariants[$matchIndex]['id'] ?? 0);
                }
            }

            if ($basalamVariationId <= 0) {
                $message = 'تنوع جدید/نامشخص Woo #' . $wcVariationId
                    . ' به variation باسلام نگاشت نشده؛ ایجاد variation بعد از ساخت اولیه خودکار انجام نشد.';
                $warnings[] = $message;
                $this->saveVariationMap(
                    $wcVariationId, $wcProductId, $basalamProductId, null,
                    (string)($wooVariation['sku'] ?? ''), 'unmatched', $message
                );
                continue;
            }

            $priceMultiplier = max(0.0001, (float)getSetting('basalam_price_multiplier', '1'));
            $update = [
                'primary_price' => $this->wooPriceToBasalam($wooVariation, $priceMultiplier),
                'stock' => $this->wooStock($wooVariation),
            ];
            $sku = trim((string)($wooVariation['sku'] ?? ''));
            if (
                $sku !== ''
                && ($skuCounts[$sku] ?? 0) === 1
                && $sku !== $basalamParentSku
            ) {
                $update['sku'] = $sku;
            }

            $res = $this->basalam->updateProductVariation(
                $basalamProductId,
                $basalamVariationId,
                $update
            );

            if ($res['error']) {
                $message = 'تنوع Woo #' . $wcVariationId . ': ' . $res['error'];
                $warnings[] = $message;
                $this->saveVariationMap(
                    $wcVariationId, $wcProductId, $basalamProductId,
                    $basalamVariationId, $sku, 'error', $message
                );
                continue;
            }

            $this->saveVariationMap(
                $wcVariationId, $wcProductId, $basalamProductId,
                $basalamVariationId, $sku, 'synced', null
            );
        }

        return ['warnings' => $warnings];
    }

    private function getVariationMapsByProduct(int $wcProductId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM basalam_variation_map WHERE wc_product_id = :id'
        );
        $stmt->execute(['id' => $wcProductId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['wc_variation_id']] = $row;
        }
        return $result;
    }

    private function findVariantMatchIndex(array $wooVariation, array $basalamVariants, array $used): ?int
    {
        // Attribute/property signatures are more specific than SKU and must be
        // preferred. Existing catalogs often reuse a parent SKU across colors.
        $wooSignature = $this->wooVariationSignature($wooVariation);
        if ($wooSignature !== '') {
            $signatureMatches = [];
            foreach ($basalamVariants as $index => $variant) {
                if (!empty($used[$index])) {
                    continue;
                }
                if ($wooSignature === $this->basalamVariationSignature((array)$variant)) {
                    $signatureMatches[] = (int)$index;
                }
            }
            if (count($signatureMatches) === 1) {
                return $signatureMatches[0];
            }
        }

        $wooSku = trim((string)($wooVariation['sku'] ?? ''));
        if ($wooSku !== '') {
            $skuMatches = [];
            foreach ($basalamVariants as $index => $variant) {
                if (!empty($used[$index])) {
                    continue;
                }
                if ($wooSku === trim((string)($variant['sku'] ?? ''))) {
                    $skuMatches[] = (int)$index;
                }
            }
            if (count($skuMatches) === 1) {
                return $skuMatches[0];
            }
        }

        // Some legacy Woo products have one variation with no attributes, while
        // the already-existing Basalam product also has one blank-property
        // variation. A unique blank-to-blank match is deterministic and safe.
        if ($wooSignature === '') {
            $blankMatches = [];
            foreach ($basalamVariants as $index => $variant) {
                if (!empty($used[$index])) {
                    continue;
                }
                if ($this->basalamVariationSignature((array)$variant) === '') {
                    $blankMatches[] = (int)$index;
                }
            }
            if (count($blankMatches) === 1) {
                return $blankMatches[0];
            }
        }

        return null;
    }

    private function wooVariationSignature(array $variation): string
    {
        $parts = [];
        foreach (($variation['attributes'] ?? []) as $attribute) {
            $name = $this->normalizeText((string)($attribute['name'] ?? ''));
            $value = $this->normalizeText((string)($attribute['option'] ?? ''));
            if ($name !== '' && $value !== '') $parts[] = $name . '=' . $value;
        }
        sort($parts, SORT_STRING);
        return implode('|', $parts);
    }

    private function basalamVariationSignature(array $variation): string
    {
        $parts = [];
        foreach (($variation['properties'] ?? []) as $property) {
            $rawName = $property['property'] ?? '';
            if (is_array($rawName)) {
                $rawName = $rawName['title'] ?? $rawName['name'] ?? $rawName['value'] ?? '';
            }

            $rawValue = $property['value'] ?? '';
            if (is_array($rawValue)) {
                $rawValue = $rawValue['value'] ?? $rawValue['title'] ?? $rawValue['name'] ?? '';
            }

            $name = $this->normalizeText((string)$rawName);
            $value = $this->normalizeText((string)$rawValue);
            if ($name !== '' && $value !== '') {
                $parts[] = $name . '=' . $value;
            }
        }
        sort($parts, SORT_STRING);
        return implode('|', $parts);
    }

    private function saveProductMap(
        int $wcProductId,
        int $basalamProductId,
        ?string $hash,
        string $status,
        ?string $error
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO basalam_product_map
                (wc_product_id, basalam_product_id, last_wc_hash, sync_status, sync_error, last_synced_at)
             VALUES (:wc, :basalam, :hash, :status, :error, NOW())
             ON DUPLICATE KEY UPDATE
                basalam_product_id = VALUES(basalam_product_id),
                last_wc_hash = VALUES(last_wc_hash),
                sync_status = VALUES(sync_status),
                sync_error = VALUES(sync_error),
                last_synced_at = VALUES(last_synced_at)'
        );
        $stmt->execute([
            'wc' => $wcProductId,
            'basalam' => $basalamProductId > 0 ? $basalamProductId : null,
            'hash' => $hash,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function saveVariationMap(
        int $wcVariationId,
        int $wcProductId,
        int $basalamProductId,
        ?int $basalamVariationId,
        string $sku,
        string $status,
        ?string $error
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO basalam_variation_map
                (wc_variation_id, wc_product_id, basalam_product_id, basalam_variation_id,
                 sku, sync_status, sync_error, last_synced_at)
             VALUES (:wcv, :wcp, :bp, :bv, :sku, :status, :error, NOW())
             ON DUPLICATE KEY UPDATE
                wc_product_id = VALUES(wc_product_id),
                basalam_product_id = VALUES(basalam_product_id),
                basalam_variation_id = VALUES(basalam_variation_id),
                sku = VALUES(sku),
                sync_status = VALUES(sync_status),
                sync_error = VALUES(sync_error),
                last_synced_at = VALUES(last_synced_at)'
        );
        $stmt->execute([
            'wcv' => $wcVariationId,
            'wcp' => $wcProductId,
            'bp' => $basalamProductId > 0 ? $basalamProductId : null,
            'bv' => $basalamVariationId,
            'sku' => $sku !== '' ? $sku : null,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function syncFailure(int $wcProductId, string $message, int $basalamProductId = 0): array
    {
        try {
            $existing = $this->getProductMap($wcProductId);
            $this->saveProductMap(
                $wcProductId,
                $basalamProductId > 0 ? $basalamProductId : (int)($existing['basalam_product_id'] ?? 0),
                $existing['last_wc_hash'] ?? null,
                'error',
                $message
            );
        } catch (Throwable $e) {
            error_log('[wc-manager] unable to persist Basalam sync error: ' . $e->getMessage());
        }

        logActivity('basalam_sync_error', 'product:' . $wcProductId, $message);

        return [
            'success' => false,
            'skipped' => false,
            'message' => $message,
            'wc_product_id' => $wcProductId,
            'basalam_product_id' => $basalamProductId,
            'warnings' => [],
        ];
    }

    private function computeWooHash(array $product, array $variations, array $categoryMap): string
    {
        $relevant = [
            'product' => [
                'id' => $product['id'] ?? null,
                'name' => $product['name'] ?? null,
                'status' => $product['status'] ?? null,
                'type' => $product['type'] ?? null,
                'sku' => $product['sku'] ?? null,
                'price' => $product['price'] ?? null,
                'regular_price' => $product['regular_price'] ?? null,
                'sale_price' => $product['sale_price'] ?? null,
                'manage_stock' => $product['manage_stock'] ?? null,
                'stock_quantity' => $product['stock_quantity'] ?? null,
                'stock_status' => $product['stock_status'] ?? null,
                'weight' => $product['weight'] ?? null,
                'short_description' => $product['short_description'] ?? null,
                'description' => $product['description'] ?? null,
                'categories' => $product['categories'] ?? [],
                'tags' => $product['tags'] ?? [],
                'attributes' => $product['attributes'] ?? [],
                'dimensions' => $product['dimensions'] ?? [],
                'images' => array_map(
                    fn($image) => ['id' => $image['id'] ?? null, 'src' => $image['src'] ?? null],
                    $product['images'] ?? []
                ),
            ],
            'variations' => array_map(
                fn($variation) => [
                    'id' => $variation['id'] ?? null,
                    'sku' => $variation['sku'] ?? null,
                    'price' => $variation['price'] ?? null,
                    'regular_price' => $variation['regular_price'] ?? null,
                    'sale_price' => $variation['sale_price'] ?? null,
                    'manage_stock' => $variation['manage_stock'] ?? null,
                    'stock_quantity' => $variation['stock_quantity'] ?? null,
                    'stock_status' => $variation['stock_status'] ?? null,
                    'attributes' => $variation['attributes'] ?? [],
                ],
                $variations
            ),
            'basalam_category_id' => (int)$categoryMap['basalam_category_id'],
            'settings' => [
                'price_multiplier' => getSetting('basalam_price_multiplier', '1'),
                'weight_multiplier' => getSetting('basalam_weight_multiplier', '1000'),
                'preparation_days' => getSetting('basalam_preparation_days', '1'),
                'default_package_weight' => getSetting('basalam_default_package_weight', '0'),
                'unmanaged_stock' => getSetting('basalam_unmanaged_stock', '1'),
                'sync_images' => getSetting('basalam_sync_images', '1'),
                'sync_attributes' => getSetting('basalam_sync_attributes', '1'),
                'attribute_sync_version' => '1',
            ],
        ];

        return hash('sha256', json_encode($relevant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function settingBool(string $key, bool $default): bool
    {
        $value = getSetting($key, $default ? '1' : '0');
        return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function limitText(string $text, int $max): string
    {
        if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
        return substr($text, 0, $max);
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
