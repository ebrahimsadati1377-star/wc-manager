<?php

class WooCommerceClient
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $wpUsername;
    private string $wpAppPassword;

    public function __construct(
        ?string $baseUrl = null,
        ?string $consumerKey = null,
        ?string $consumerSecret = null,
        ?string $wpUsername = null,
        ?string $wpAppPassword = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string)getSetting('store_url'), '/');
        $this->consumerKey = $consumerKey ?? (string)getSetting('consumer_key');
        $this->consumerSecret = $consumerSecret ?? (string)getSetting('consumer_secret');
        $this->wpUsername = $wpUsername ?? (string)getSetting('wp_username');
        $this->wpAppPassword = $wpAppPassword ?? (string)getSetting('wp_app_password');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->consumerKey !== '' && $this->consumerSecret !== '';
    }

    public function isWpConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->wpUsername !== '' && $this->wpAppPassword !== '';
    }

    public function getBaseUrl(): string { return $this->baseUrl; }
    public function getWpUsername(): string { return $this->wpUsername; }
    public function getWpAppPassword(): string { return $this->wpAppPassword; }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $body = []): array
    {
        return $this->request('POST', $endpoint, [], $body);
    }

    public function put(string $endpoint, array $body = []): array
    {
        return $this->request('PUT', $endpoint, [], $body);
    }

    public function delete(string $endpoint, array $params = []): array
    {
        return $this->request('DELETE', $endpoint, $params);
    }

    /**
     * Normalized response shape:
     * ['status' => int, 'body' => array, 'headers' => array, 'error' => ?string]
     */
    private function request(string $method, string $endpoint, array $params = [], ?array $body = null): array
    {
        $endpoint = ltrim($endpoint, '/');
        $isWordPress = str_starts_with($endpoint, 'wp-json/');

        if ($this->baseUrl === '') {
            return $this->failure('آدرس فروشگاه تنظیم نشده است.');
        }
        if ($isWordPress && !$this->isWpConfigured()) {
            return $this->failure('اتصال وردپرس تنظیم نشده است.');
        }
        if (!$isWordPress && !$this->isConfigured()) {
            return $this->failure('اتصال ووکامرس تنظیم نشده است.');
        }

        $url = $isWordPress
            ? $this->baseUrl . '/' . $endpoint
            : $this->baseUrl . '/wp-json/wc/v3/' . $endpoint;

        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];

        if ($isWordPress) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $this->wpUsername . ':' . $this->wpAppPassword;
        } else {
            // WooCommerce REST over HTTPS supports HTTP Basic auth using ck/cs.
            // Credentials no longer appear in the URL/query string.
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $this->consumerKey . ':' . $this->consumerSecret;
        }

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return $this->failure('تبدیل داده درخواست به JSON ناموفق بود.');
            }
            $options[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        return $this->execute($options);
    }

    private function execute(array $options): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return $this->failure('راه‌اندازی cURL ناموفق بود.');
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return $this->failure('خطای اتصال به API: ' . ($curlError ?: 'خطای نامشخص'));
        }

        $rawHeaders = substr($response, 0, $headerLen);
        $rawBody = substr($response, $headerLen);
        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);

        $headers = [
            'total' => $this->headerInt($rawHeaders, 'X-WP-Total'),
            'total_pages' => $this->headerInt($rawHeaders, 'X-WP-TotalPages'),
        ];

        if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => $status,
                'body' => [],
                'headers' => $headers,
                'error' => 'پاسخ نامعتبر از API دریافت شد (JSON قابل خواندن نیست).',
            ];
        }

        $decoded = is_array($decoded) ? $decoded : [];
        $error = null;
        if ($status < 200 || $status >= 300) {
            $error = $this->extractApiError($decoded, $status);
        }

        return [
            'status' => $status,
            'body' => $decoded,
            'headers' => $headers,
            'error' => $error,
        ];
    }

    private function extractApiError(array $body, int $status): string
    {
        if (!empty($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }
        if (!empty($body['data']['message']) && is_string($body['data']['message'])) {
            return $body['data']['message'];
        }
        return $status > 0 ? 'خطای HTTP ' . $status : 'خطای نامشخص API';
    }

    private function headerInt(string $headers, string $name): ?int
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(\d+)\s*$/im', $headers, $match)) {
            return (int)$match[1];
        }
        return null;
    }

    private function failure(string $message, int $status = 0): array
    {
        return ['status' => $status, 'body' => [], 'headers' => [], 'error' => $message];
    }

    // ---------------- WordPress posts ----------------

    public function createPost(string $title, string $content, string $status = 'draft'): array
    {
        return $this->createPostWithCategories($title, $content, $status);
    }

    public function createPostWithCategories(string $title, string $content, string $status = 'draft', array $categoryIds = []): array
    {
        $body = ['title' => $title, 'content' => $content, 'status' => $status];
        if ($categoryIds) $body['categories'] = array_values($categoryIds);
        return $this->request('POST', 'wp-json/wp/v2/posts', [], $body);
    }

    public function getPost(int $id): array
    {
        return $this->request('GET', 'wp-json/wp/v2/posts/' . $id);
    }

    public function updatePost(int $id, string $title, string $content, string $status): array
    {
        return $this->updatePostWithCategories($id, $title, $content, $status);
    }

    public function updatePostWithCategories(int $id, string $title, string $content, string $status, array $categoryIds = []): array
    {
        $body = ['title' => $title, 'content' => $content, 'status' => $status];
        if ($categoryIds) $body['categories'] = array_values($categoryIds);
        return $this->request('POST', 'wp-json/wp/v2/posts/' . $id, [], $body);
    }

    public function deletePost(int $postId): array
    {
        return $this->request('DELETE', 'wp-json/wp/v2/posts/' . $postId, ['force' => 'true']);
    }

    public function setPostFeaturedImage(int $postId, int $mediaId): array
    {
        return $this->request('POST', 'wp-json/wp/v2/posts/' . $postId, [], ['featured_media' => $mediaId]);
    }

    public function getPostCategories(array $params = []): array
    {
        return $this->request('GET', 'wp-json/wp/v2/categories', array_merge(['per_page' => 100], $params));
    }

    public function createPostCategory(string $name, string $slug = ''): array
    {
        $body = ['name' => $name];
        if ($slug !== '') $body['slug'] = $slug;
        return $this->request('POST', 'wp-json/wp/v2/categories', [], $body);
    }

    public function uploadMedia(string $filePath, string $fileName = ''): array
    {
        if (!$this->isWpConfigured()) {
            return $this->failure('برای آپلود رسانه، اتصال وردپرس باید تنظیم شده باشد.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            return $this->failure('فایل برای آپلود یافت نشد یا قابل خواندن نیست.');
        }

        $fileName = $fileName !== '' ? basename($fileName) : basename($filePath);
        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            return $this->failure('خواندن فایل برای آپلود ناموفق بود.');
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : false;
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';

        $options = [
            CURLOPT_URL => $this->baseUrl . '/wp-json/wp/v2/media',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fileData,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->wpUsername . ':' . $this->wpAppPassword,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Disposition: attachment; filename="' . addcslashes($fileName, "\\\"") . '"',
                'Content-Type: ' . $mime,
            ],
        ];

        return $this->execute($options);
    }

    // ---------------- Products ----------------

    public function getProducts(array $params = []): array { return $this->get('products', $params); }
    public function getProduct(int $id): array { return $this->get('products/' . $id); }
    public function createProduct(array $data): array { return $this->post('products', $data); }
    public function updateProduct(int $id, array $data): array { return $this->put('products/' . $id, $data); }
    public function deleteProduct(int $id, bool $force = true): array { return $this->delete('products/' . $id, ['force' => $force ? 'true' : 'false']); }

    // ---------------- Variations ----------------

    public function getVariations(int $productId, array $params = []): array { return $this->get('products/' . $productId . '/variations', array_merge(['per_page' => 100], $params)); }
    public function getVariation(int $productId, int $variationId): array { return $this->get('products/' . $productId . '/variations/' . $variationId); }
    public function createVariation(int $productId, array $data): array { return $this->post('products/' . $productId . '/variations', $data); }
    public function updateVariation(int $productId, int $variationId, array $data): array { return $this->put('products/' . $productId . '/variations/' . $variationId, $data); }
    public function deleteVariation(int $productId, int $variationId): array { return $this->delete('products/' . $productId . '/variations/' . $variationId, ['force' => 'true']); }

    public function batchVariations(int $productId, array $create = [], array $update = [], array $delete = []): array
    {
        return $this->post('products/' . $productId . '/variations/batch', array_filter([
            'create' => $create,
            'update' => $update,
            'delete' => $delete,
        ]));
    }

    // ---------------- Categories / attributes / tags ----------------

    public function getCategories(array $params = []): array { return $this->get('products/categories', array_merge(['per_page' => 100], $params)); }
    public function createCategory(array $data): array { return $this->post('products/categories', $data); }
    public function updateCategory(int $id, array $data): array { return $this->put('products/categories/' . $id, $data); }
    public function deleteCategory(int $id): array { return $this->delete('products/categories/' . $id, ['force' => 'true']); }

    public function getAttributes(): array { return $this->get('products/attributes'); }
    public function createAttribute(array $data): array { return $this->post('products/attributes', $data); }
    public function getAttributeTerms(int $attributeId, array $params = []): array { return $this->get('products/attributes/' . $attributeId . '/terms', array_merge(['per_page' => 100], $params)); }
    public function createAttributeTerm(int $attributeId, array $data): array { return $this->post('products/attributes/' . $attributeId . '/terms', $data); }

    public function getTags(array $params = []): array { return $this->get('products/tags', array_merge(['per_page' => 100], $params)); }
    public function createTag(array $data): array { return $this->post('products/tags', $data); }

    public function ping(): array { return $this->get('products', ['per_page' => 1]); }
}
