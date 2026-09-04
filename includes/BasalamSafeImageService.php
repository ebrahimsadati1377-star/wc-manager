<?php

class BasalamSafeImageService
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
            "CREATE TABLE IF NOT EXISTS basalam_safe_image_jobs (
                wc_product_id BIGINT UNSIGNED NOT NULL,
                basalam_product_id BIGINT UNSIGNED NOT NULL,
                crop_top_percent TINYINT UNSIGNED NOT NULL DEFAULT 28,
                image_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                last_reason TEXT NULL,
                last_error TEXT NULL,
                submitted_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (wc_product_id),
                KEY idx_basalam_product_id (basalam_product_id),
                KEY idx_last_status (last_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function getJob(int $wcProductId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM basalam_safe_image_jobs WHERE wc_product_id = :id LIMIT 1');
        $stmt->execute(['id' => $wcProductId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function inspectBasalamProduct(int $basalamProductId): array
    {
        $res = $this->basalam->getProduct($basalamProductId, true);
        if ($res['error']) {
            return [
                'success' => false,
                'message' => (string)$res['error'],
                'product_id' => $basalamProductId,
                'illegal_photos' => [],
                'reasons' => [],
            ];
        }

        $product = is_array($res['body'] ?? null) ? $res['body'] : [];
        $revision = is_array($product['revision'] ?? null) ? $product['revision'] : [];
        $metadata = is_array($revision['metadata'] ?? null) ? $revision['metadata'] : [];
        $illegal = is_array($metadata['illegal_photos'] ?? null) ? $metadata['illegal_photos'] : [];
        $reasons = [];

        foreach ($illegal as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            foreach ((array)($photo['rejection_reasons'] ?? []) as $reason) {
                if (is_array($reason)) {
                    $text = trim((string)($reason['name'] ?? $reason['message'] ?? $reason['description'] ?? ''));
                } else {
                    $text = trim((string)$reason);
                }
                if ($text !== '') {
                    $reasons[$text] = true;
                }
            }
        }

        $status = $product['status'] ?? null;
        $statusValue = is_array($status) ? (int)($status['value'] ?? 0) : (is_numeric($status) ? (int)$status : 0);
        $statusName = is_array($status) ? trim((string)($status['name'] ?? '')) : (is_string($status) ? trim($status) : '');

        return [
            'success' => true,
            'message' => '',
            'product_id' => $basalamProductId,
            'title' => (string)($product['name'] ?? $product['title'] ?? ''),
            'status' => ['value' => $statusValue, 'name' => $statusName],
            'illegal_photos' => array_values($illegal),
            'reasons' => array_keys($reasons),
            'is_showable' => array_key_exists('is_showable', $product) ? (bool)$product['is_showable'] : null,
            'is_available' => array_key_exists('is_available', $product) ? (bool)$product['is_available'] : null,
            'is_product_for_revision' => array_key_exists('is_product_for_revision', $product) ? (bool)$product['is_product_for_revision'] : null,
        ];
    }

    public function prepareAndSubmit(int $wcProductId, int $cropTopPercent = 28): array
    {
        $cropTopPercent = max(15, min(40, $cropTopPercent));

        if (!$this->wc->isConfigured()) {
            return $this->failure($wcProductId, 0, 'اتصال ووکامرس تنظیم نشده است.');
        }
        if (!$this->basalam->isConfigured()) {
            return $this->failure($wcProductId, 0, 'اتصال باسلام تنظیم نشده است.');
        }

        $stmt = $this->db->prepare(
            'SELECT basalam_product_id FROM basalam_product_map WHERE wc_product_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $wcProductId]);
        $map = $stmt->fetch();
        $basalamProductId = is_array($map) ? (int)($map['basalam_product_id'] ?? 0) : 0;
        if ($basalamProductId <= 0) {
            return $this->failure($wcProductId, 0, 'این محصول هنوز به محصول باسلام مپ نشده است.');
        }

        $moderation = $this->inspectBasalamProduct($basalamProductId);
        if (!$moderation['success']) {
            return $this->failure($wcProductId, $basalamProductId, 'خواندن دلیل رد باسلام ناموفق بود: ' . $moderation['message']);
        }
        if (empty($moderation['illegal_photos'])) {
            return $this->failure(
                $wcProductId,
                $basalamProductId,
                'باسلام برای این محصول عکس ردشده ثبت نکرده؛ برای جلوگیری از تغییر بی‌دلیل تصویر، عملیات متوقف شد.'
            );
        }

        $productRes = $this->wc->getProduct($wcProductId);
        if ($productRes['error']) {
            return $this->failure($wcProductId, $basalamProductId, 'خواندن محصول ووکامرس ناموفق بود: ' . $productRes['error']);
        }
        $product = is_array($productRes['body'] ?? null) ? $productRes['body'] : [];
        $images = is_array($product['images'] ?? null) ? $product['images'] : [];
        if (!$images) {
            return $this->failure($wcProductId, $basalamProductId, 'محصول ووکامرس تصویری برای اصلاح ندارد.');
        }

        $maxImages = min(10, max(1, (int)getSetting('basalam_max_images', '6')));
        $fileIds = [];
        $warnings = [];
        foreach (array_slice($images, 0, $maxImages) as $image) {
            $url = trim((string)($image['src'] ?? ''));
            if ($url === '') {
                continue;
            }
            $upload = BasalamSafeImageProcessor::upload($this->basalam, $url, $cropTopPercent);
            if ($upload['error']) {
                $warnings[] = (string)$upload['error'];
                continue;
            }
            $fileId = (int)($upload['body']['id'] ?? 0);
            if ($fileId > 0) {
                $fileIds[] = $fileId;
            } else {
                $warnings[] = 'باسلام برای یک تصویر اصلاح‌شده شناسه فایل برنگرداند.';
            }
        }

        if (!$fileIds) {
            return $this->failure(
                $wcProductId,
                $basalamProductId,
                'هیچ تصویر اصلاح‌شده‌ای با موفقیت در باسلام آپلود نشد.' . ($warnings ? ' ' . implode(' | ', $warnings) : '')
            );
        }

        $payload = [
            'status' => 2976,
            'photo' => array_shift($fileIds),
        ];
        if ($fileIds) {
            $payload['photos'] = array_values($fileIds);
        } else {
            $payload['photos'] = [];
        }

        $update = $this->basalam->updateProduct($basalamProductId, $payload);
        if ($update['error']) {
            return $this->failure(
                $wcProductId,
                $basalamProductId,
                'ارسال تصاویر اصلاح‌شده به باسلام ناموفق بود: ' . $update['error']
            );
        }

        $allImageCount = 1 + count($fileIds);
        $reasonText = implode(' | ', (array)($moderation['reasons'] ?? []));
        $this->saveJob(
            $wcProductId,
            $basalamProductId,
            $cropTopPercent,
            $allImageCount,
            'submitted',
            $reasonText,
            $warnings ? implode(' | ', $warnings) : null,
            true
        );

        $refreshed = $this->inspectBasalamProduct($basalamProductId);
        logActivity(
            'basalam_safe_images_submit',
            'product:' . $wcProductId,
            sprintf(
                'Woo #%d -> Basalam #%d | safe crop %d%% | %d images%s',
                $wcProductId,
                $basalamProductId,
                $cropTopPercent,
                $allImageCount,
                $warnings ? ' | warnings: ' . implode(' | ', $warnings) : ''
            )
        );

        return [
            'success' => true,
            'message' => $warnings
                ? 'تصاویر اصلاح و برای بررسی مجدد ارسال شدند؛ بعضی تصاویر هشدار داشتند.'
                : 'تصاویر اصلاح و برای بررسی مجدد باسلام ارسال شدند.',
            'wc_product_id' => $wcProductId,
            'basalam_product_id' => $basalamProductId,
            'crop_top_percent' => $cropTopPercent,
            'image_count' => $allImageCount,
            'warnings' => $warnings,
            'moderation_before' => $moderation,
            'moderation_after' => $refreshed,
        ];
    }

    private function saveJob(
        int $wcProductId,
        int $basalamProductId,
        int $cropTopPercent,
        int $imageCount,
        string $status,
        ?string $reason,
        ?string $error,
        bool $submitted
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO basalam_safe_image_jobs
                (wc_product_id, basalam_product_id, crop_top_percent, image_count, last_status, last_reason, last_error, submitted_at)
             VALUES (:wc, :basalam, :crop, :count, :status, :reason, :error, :submitted)
             ON DUPLICATE KEY UPDATE
                basalam_product_id = VALUES(basalam_product_id),
                crop_top_percent = VALUES(crop_top_percent),
                image_count = VALUES(image_count),
                last_status = VALUES(last_status),
                last_reason = VALUES(last_reason),
                last_error = VALUES(last_error),
                submitted_at = VALUES(submitted_at)'
        );
        $stmt->execute([
            'wc' => $wcProductId,
            'basalam' => $basalamProductId,
            'crop' => $cropTopPercent,
            'count' => $imageCount,
            'status' => $status,
            'reason' => $reason,
            'error' => $error,
            'submitted' => $submitted ? date('Y-m-d H:i:s') : null,
        ]);
    }

    private function failure(int $wcProductId, int $basalamProductId, string $message): array
    {
        if ($wcProductId > 0 && $basalamProductId > 0) {
            try {
                $this->saveJob($wcProductId, $basalamProductId, 28, 0, 'error', null, $message, false);
            } catch (Throwable $e) {
                error_log('[wc-manager] failed to persist safe-image error: ' . $e->getMessage());
            }
        }
        return [
            'success' => false,
            'message' => $message,
            'wc_product_id' => $wcProductId,
            'basalam_product_id' => $basalamProductId,
            'warnings' => [],
        ];
    }
}
