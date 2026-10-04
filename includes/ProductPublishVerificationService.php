<?php

class ProductPublishVerificationService
{
    private ProductWorkflowRepository $repo;
    private WooCommerceClient $wc;

    public function __construct(
        ?ProductWorkflowRepository $repo = null,
        ?WooCommerceClient $wc = null
    ) {
        $this->repo = $repo ?? new ProductWorkflowRepository();
        $this->wc = $wc ?? new WooCommerceClient();
    }

    public function verify(int $jobId, ?int $productId = null): array
    {
        $job = $this->repo->get($jobId);
        $productId = $productId ?: (int)($job['product_id'] ?? 0);
        if ($productId < 1) {
            throw new RuntimeException('برای Verification شناسه محصول وجود ندارد.');
        }

        $response = $this->wc->getProduct($productId);
        if (!empty($response['error'])) {
            throw new RuntimeException('دریافت محصول برای Verification ناموفق بود: ' . $response['error']);
        }
        $product = is_array($response['body'] ?? null) ? $response['body'] : [];
        if (!$product) {
            throw new RuntimeException('WooCommerce محصول را برای Verification برنگرداند.');
        }

        $expectedImages = $this->repo->images($jobId);
        $required = max(1, (int)getSetting('required_product_images_count', '7'));
        $actualImages = (array)($product['images'] ?? []);
        $expectedIds = array_values(array_map(
            static fn($row) => (int)($row['wordpress_media_id'] ?? 0),
            $expectedImages
        ));
        $actualIds = array_values(array_map(
            static fn($row) => (int)($row['id'] ?? 0),
            $actualImages
        ));

        $seo = (array)$job['seo_json'];
        $meta = [];
        foreach ((array)($product['meta_data'] ?? []) as $row) {
            $key = (string)($row['key'] ?? '');
            if ($key !== '') $meta[$key] = (string)($row['value'] ?? '');
        }

        $checks = [
            'status_publish' => (string)($product['status'] ?? '') === 'publish',
            'image_count' => count($actualImages) === $required,
            'image_ids_match' => $expectedIds === array_slice($actualIds, 0, $required),
            'regular_price' => $this->samePrice($job['regular_price'], $product['regular_price'] ?? ''),
            'sale_price' => $this->samePrice($job['sale_price'], $product['sale_price'] ?? ''),
            'categories' => $this->categoriesMatch($job, $seo, (array)($product['categories'] ?? [])),
            'seo_title_saved' => $this->metaMatches(
                $meta,
                ['rank_math_title','_yoast_wpseo_title'],
                (string)($seo['seo_title'] ?? '')
            ),
            'seo_description_saved' => $this->metaMatches(
                $meta,
                ['rank_math_description','_yoast_wpseo_metadesc'],
                (string)($seo['meta_description'] ?? '')
            ),
            'seo_focus_keyword_saved' => $this->metaMatches(
                $meta,
                ['rank_math_focus_keyword','_yoast_wpseo_focuskw'],
                (string)($seo['focus_keyword'] ?? '')
            ),
        ];

        if ($job['stock_quantity'] !== null && $job['stock_quantity'] !== '') {
            $checks['stock_quantity'] =
                (int)($product['stock_quantity'] ?? -1) === (int)$job['stock_quantity'];
            $checks['stock_status'] =
                (string)($product['stock_status'] ?? '') === ((int)$job['stock_quantity'] > 0 ? 'instock' : 'outofstock');
        }

        $altOk = true;
        foreach (array_slice($actualImages, 0, $required) as $image) {
            if (trim((string)($image['alt'] ?? '')) === '') {
                $altOk = false;
                break;
            }
        }
        $checks['image_alt_texts'] = $altOk;

        $permalink = trim((string)($product['permalink'] ?? ''));
        $page = $this->pageCheck($permalink);
        $checks['public_page_reachable'] = $page['reachable'];

        $essential = [
            'status_publish','image_count','image_ids_match',
            'regular_price','sale_price','categories',
            'public_page_reachable',
        ];
        if (array_key_exists('stock_quantity', $checks)) {
            $essential[] = 'stock_quantity';
            $essential[] = 'stock_status';
        }

        $pass = true;
        foreach ($essential as $key) {
            if (empty($checks[$key])) {
                $pass = false;
                break;
            }
        }

        $warnings = [];
        foreach ($checks as $key => $ok) {
            if (!$ok && !in_array($key, $essential, true)) $warnings[] = $key;
        }

        $result = [
            'verified' => $pass,
            'verification_status' => $pass
                ? ($warnings ? 'verified_with_warnings' : 'verified')
                : 'issues',
            'checks' => $checks,
            'warnings' => $warnings,
            'page' => $page,
            'product_id' => $productId,
            'permalink' => $permalink,
            'verified_at' => date('c'),
        ];

        $this->repo->update($jobId, [
            'workflow_status' => $pass ? 'published_verified' : 'published_with_issues',
            'current_step' => $pass ? 'verified' : 'published_with_issues',
            'progress_percent' => 100,
            'publish_verification_json' => $result,
            'verification_status' => $result['verification_status'],
            'error_message' => $pass ? null : 'محصول منتشر شد اما Verification ایراد پیدا کرد.',
        ]);
        $this->repo->event(
            $jobId,
            $pass ? 'info' : 'warning',
            'publish_verification',
            $pass ? 'Published product verified.' : 'Published product has verification issues.',
            $result
        );

        return $result;
    }

    private function categoriesMatch(array $job, array $seo, array $actual): bool
    {
        $expected = array_values(array_filter(array_map(
            'intval',
            (array)($seo['category_ids'] ?? [])
        )));
        if (!$expected && !empty($job['category_id'])) {
            $expected = [(int)$job['category_id']];
        }
        if (!$expected) return true;

        $actualIds = array_values(array_filter(array_map(
            static fn($row) => (int)($row['id'] ?? 0),
            $actual
        )));
        foreach ($expected as $id) {
            if (!in_array($id, $actualIds, true)) return false;
        }
        return true;
    }

    private function metaMatches(array $meta, array $keys, string $expected): bool
    {
        if ($expected === '') return true;
        foreach ($keys as $key) {
            if (isset($meta[$key]) && trim($meta[$key]) === trim($expected)) {
                return true;
            }
        }
        return false;
    }

    private function samePrice($expected, $actual): bool
    {
        $emptyExpected = $expected === null || $expected === '';
        $emptyActual = $actual === null || $actual === '';
        if ($emptyExpected) return $emptyActual || (float)$actual === 0.0;
        if (!is_numeric($expected) || !is_numeric($actual)) return false;
        return abs((float)$expected - (float)$actual) < 0.001;
    }

    private function pageCheck(string $url): array
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['reachable' => false, 'http_status' => 0];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'BAJI-WC-Manager/PublishVerifier',
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return [
            'reachable' => $status >= 200 && $status < 400,
            'http_status' => $status,
            'final_url' => $finalUrl,
        ];
    }
}
