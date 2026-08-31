<?php

/**
 * کلاینت پیشرفته برای ارتباط با WooCommerce REST API (v3)
 * پشتیبانی همزمان از Consumer Key/Secret و WordPress Application Password
 */
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
        $this->baseUrl        = rtrim($baseUrl ?? (string)getSetting('store_url'), '/');
        $this->consumerKey    = $consumerKey ?? (string)getSetting('consumer_key');
        $this->consumerSecret = $consumerSecret ?? (string)getSetting('consumer_secret');
        $this->wpUsername     = $wpUsername ?? (string)getSetting('wp_username');
        $this->wpAppPassword  = $wpAppPassword ?? (string)getSetting('wp_app_password');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->consumerKey !== '' && $this->consumerSecret !== '';
    }

    public function isWpConfigured(): bool
    {
        return $this->wpUsername !== '' && $this->wpAppPassword !== '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getWpUsername(): string
    {
        return $this->wpUsername;
    }

    public function getWpAppPassword(): string
    {
        return $this->wpAppPassword;
    }

    public function createPost(string $title, string $content, string $status = 'draft'): array
    {
        $body = [
            'title'   => $title,
            'content' => $content,
            'status'  => $status
        ];

        // با دادن مسیر کامل، متد هوشمند ما متوجه می‌شود که نباید مسیر ووکامرس را به آن بچسباند
        return $this->request('POST', 'wp-json/wp/v2/posts', [], $body);
    }

    public function createPostWithCategories(string $title, string $content, string $status = 'draft', array $categoryIds = []): array
    {
        $body = [
            'title'   => $title,
            'content' => $content,
            'status'  => $status
        ];

        if (!empty($categoryIds)) {
            $body['categories'] = $categoryIds;
        }

        return $this->request('POST', 'wp-json/wp/v2/posts', [], $body);
    }

    public function getPost(int $id): array
    {
        return $this->request('GET', 'wp-json/wp/v2/posts/' . $id);
    }

    public function updatePost(int $id, string $title, string $content, string $status): array
    {
        $postData = [
            'title'   => $title,
            'content' => $content,
            'status'  => $status
        ];
        // در وردپرس برای ویرایش هم از متد POST روی مسیر مقاله استفاده می‌شود
        return $this->request('POST', 'wp-json/wp/v2/posts/' . $id, [], $postData);
    }

    public function updatePostWithCategories(int $id, string $title, string $content, string $status, array $categoryIds = []): array
    {
        $postData = [
            'title'   => $title,
            'content' => $content,
            'status'  => $status
        ];

        if (!empty($categoryIds)) {
            $postData['categories'] = $categoryIds;
        }

        return $this->request('POST', 'wp-json/wp/v2/posts/' . $id, [], $postData);
    }
    // در کلاس WooCommerceClient اضافه کنید:

    // در کلاس WooCommerceClient این متد را به این شکل تغییر دهید:
    public function deletePost(int $postId): array
    {
        // استفاده از متد public برای حذف
        return $this->delete('wp-json/wp/v2/posts/' . $postId, ['force' => 'true']);
    }
    // --- متدهای پایه‌ای که پاک شده بودند و بازگردانده شدند ---

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
     * @return array{status:int, body:array, headers:array, error:?string}
     */
    private function request(string $method, string $endpoint, array $params = [], ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 0, 'body' => [], 'headers' => [], 'error' => 'اتصال به ووکامرس تنظیم نشده است.'];
        }

        $endpoint = ltrim($endpoint, '/');
        
        // 💡 تشخیص هوشمند: اگر مسیر خودش با wp-json شروع شد (مثل مجله)، مسیر ووکامرس را به آن اضافه نکن
        if (strpos($endpoint, 'wp-json/') === 0) {
            $url = $this->baseUrl . '/' . $endpoint;
        } else {
            $url = $this->baseUrl . '/wp-json/wc/v3/' . $endpoint;
        }

        $ch = curl_init();
        $headers = ['Accept: application/json'];

        // مکانیزم احراز هویت هوشمند ترکیبی شما
        if ($this->wpUsername !== '' && $this->wpAppPassword !== '') {
            $base64Auth = base64_encode($this->wpUsername . ':' . $this->wpAppPassword);
            $headers[] = 'Authorization: Basic ' . $base64Auth;
        } else {
            $params['consumer_key']    = $this->consumerKey;
            $params['consumer_secret'] = $this->consumerSecret;
        }

        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 300, 
            CURLOPT_CONNECTTIMEOUT => 60,  
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 0, 'body' => [], 'headers' => [], 'error' => 'خطای اتصال: ' . $curlError];
        }

        $rawHeaders = substr($response, 0, $headerLen);
        $rawBody    = substr($response, $headerLen);
        $decoded    = json_decode($rawBody, true);

        $totalPages = null;
        if (preg_match('/X-WP-TotalPages:\s*(\d+)/i', $rawHeaders, $m)) {
            $totalPages = (int)$m[1];
        }
        $total = null;
        if (preg_match('/X-WP-Total:\s*(\d+)/i', $rawHeaders, $m)) {
            $total = (int)$m[1];
        }

        $error = null;
        if ($status >= 400) {
            $error = $decoded['message'] ?? ('خطای HTTP ' . $status);
        }

        return [
            'status'  => $status,
            'body'    => is_array($decoded) ? $decoded : [],
            'headers' => ['total' => $total, 'total_pages' => $totalPages],
            'error'   => $error,
        ];
    }

    // ---------------- Products ----------------

    public function getProducts(array $params = []): array
    {
        return $this->get('products', $params);
    }

    public function getProduct(int $id): array
    {
        return $this->get('products/' . $id);
    }

    public function createProduct(array $data): array
    {
        return $this->post('products', $data);
    }

    public function updateProduct(int $id, array $data): array
    {
        return $this->put('products/' . $id, $data);
    }

    public function deleteProduct(int $id, bool $force = true): array
    {
        return $this->delete('products/' . $id, ['force' => $force ? 'true' : 'false']);
    }

    // ---------------- Variations ----------------

    public function getVariations(int $productId, array $params = []): array
    {
        return $this->get('products/' . $productId . '/variations', array_merge(['per_page' => 100], $params));
    }

    public function getVariation(int $productId, int $variationId): array
    {
        return $this->get('products/' . $productId . '/variations/' . $variationId);
    }

    public function createVariation(int $productId, array $data): array
    {
        return $this->post('products/' . $productId . '/variations', $data);
    }

    public function updateVariation(int $productId, int $variationId, array $data): array
    {
        return $this->put('products/' . $productId . '/variations/' . $variationId, $data);
    }

    public function deleteVariation(int $productId, int $variationId): array
    {
        return $this->delete('products/' . $productId . '/variations/' . $variationId, ['force' => 'true']);
    }

    public function batchVariations(int $productId, array $create = [], array $update = [], array $delete = []): array
    {
        $body = array_filter([
            'create' => $create,
            'update' => $update,
            'delete' => $delete,
        ]);
        return $this->post('products/' . $productId . '/variations/batch', $body);
    }

    // ---------------- Categories ----------------

    public function getCategories(array $params = []): array
    {
        return $this->get('products/categories', array_merge(['per_page' => 100], $params));
    }

    public function createCategory(array $data): array
    {
        return $this->post('products/categories', $data);
    }

    public function updateCategory(int $id, array $data): array
    {
        return $this->put('products/categories/' . $id, $data);
    }

    public function deleteCategory(int $id): array
    {
        return $this->delete('products/categories/' . $id, ['force' => 'true']);
    }

    // ---------------- Attributes ----------------

    public function getAttributes(): array
    {
        return $this->get('products/attributes');
    }

    public function createAttribute(array $data): array
    {
        return $this->post('products/attributes', $data);
    }

    public function getAttributeTerms(int $attributeId, array $params = []): array
    {
        return $this->get('products/attributes/' . $attributeId . '/terms', array_merge(['per_page' => 100], $params));
    }

    public function createAttributeTerm(int $attributeId, array $data): array
    {
        return $this->post('products/attributes/' . $attributeId . '/terms', $data);
    }

    // ---------------- Tags ----------------

    public function getTags(array $params = []): array
    {
        return $this->get('products/tags', array_merge(['per_page' => 100], $params));
    }

    public function createTag(array $data): array
    {
        return $this->post('products/tags', $data);
    }

    // ---------------- System status ----------------

    public function ping(): array
    {
        return $this->get('products', ['per_page' => 1]);
    }

    // ---------------- Media ----------------

    /**
     * آپلود فایل به کتابخانه رسانه وردپرس
     *
     * @param string $filePath مسیر کامل فایل روی سرور
     * @param string $fileName نام فایل (اختیاری)
     * @return array
     */
    public function uploadMedia(string $filePath, string $fileName = ''): array
    {
        if (!file_exists($filePath)) {
            return ['status' => 0, 'body' => [], 'headers' => [], 'error' => 'فایل یافت نشد: ' . $filePath];
        }

        if (empty($fileName)) {
            $fileName = basename($filePath);
        }

        $url = $this->baseUrl . '/wp-json/wp/v2/media';

        // احراز هویت با Application Password
        if ($this->wpUsername === '' || $this->wpAppPassword === '') {
            return ['status' => 0, 'body' => [], 'headers' => [], 'error' => 'برای آپلود رسانه، نام کاربری و رمز عبور کاربردی وردپرس لازم است.'];
        }

        $ch = curl_init();

        $fileData = file_get_contents($filePath);
        $mimeType = mime_content_type($filePath);

        $headers = [
            'Content-Disposition: attachment; filename="' . $fileName . '"',
            'Content-Type: ' . $mimeType,
            'Authorization: Basic ' . base64_encode($this->wpUsername . ':' . $this->wpAppPassword)
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fileData,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 0, 'body' => [], 'headers' => [], 'error' => 'خطای آپلود: ' . $curlError];
        }

        $rawBody = substr($response, $headerLen);
        $decoded = json_decode($rawBody, true);

        $error = null;
        if ($status >= 400) {
            $error = $decoded['message'] ?? ('خطای HTTP ' . $status);
        }

        return [
            'status'  => $status,
            'body'    => is_array($decoded) ? $decoded : [],
            'headers' => [],
            'error'   => $error,
        ];
    }

    /**
     * تنظیم featured image برای یک پست
     *
     * @param int $postId شناسه پست
     * @param int $mediaId شناسه فایل رسانه‌ای
     * @return array
     */
    public function setPostFeaturedImage(int $postId, int $mediaId): array
    {
        $body = ['featured_media' => $mediaId];
        return $this->request('POST', 'wp-json/wp/v2/posts/' . $postId, [], $body);
    }

    // ---------------- Post Categories ----------------

    /**
     * دریافت لیست دسته‌بندی‌های پست از وردپرس
     *
     * @param array $params پارامترهای اضافی
     * @return array
     */
    public function getPostCategories(array $params = []): array
    {
        return $this->get('wp-json/wp/v2/categories', array_merge(['per_page' => 100], $params));
    }

    /**
     * ساخت دسته‌بندی جدید برای پست‌ها
     *
     * @param string $name نام دسته‌بندی
     * @param string $slug اسلاگ (اختیاری)
     * @return array
     */
    public function createPostCategory(string $name, string $slug = ''): array
    {
        $body = ['name' => $name];

        if (!empty($slug)) {
            $body['slug'] = $slug;
        }

        return $this->request('POST', 'wp-json/wp/v2/categories', [], $body);
    }
}