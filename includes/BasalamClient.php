<?php

class BasalamClient
{
    private string $baseUrl;
    private string $authUrl;
    private int $vendorId;
    private string $authMode;
    private string $accessToken;
    private string $clientId;
    private string $clientSecret;
    private string $scopes;
    private ?string $runtimeToken = null;
    private int $runtimeTokenExpiresAt = 0;

    public function __construct()
    {
        $this->baseUrl = rtrim((string)getSetting('basalam_base_url', 'https://openapi.basalam.com'), '/');
        $this->authUrl = (string)getSetting('basalam_auth_url', 'https://auth.basalam.com/oauth/token');
        $this->vendorId = (int)getSetting('basalam_vendor_id', '0');
        $this->authMode = (string)getSetting('basalam_auth_mode', 'personal_token');
        $this->accessToken = (string)getSetting('basalam_access_token', '');
        $this->clientId = (string)getSetting('basalam_client_id', '');
        $this->clientSecret = (string)getSetting('basalam_client_secret', '');
        $this->scopes = trim((string)getSetting(
            'basalam_scopes',
            'vendor.product.read vendor.product.write'
        ));
    }

    public function isConfigured(): bool
    {
        if ($this->vendorId <= 0) {
            return false;
        }

        if ($this->authMode === 'client_credentials') {
            return $this->clientId !== '' && $this->clientSecret !== '';
        }

        return $this->accessToken !== '';
    }

    public function getVendorId(): int
    {
        return $this->vendorId;
    }

    public function ping(): array
    {
        return $this->getVendorProducts(['page' => 1, 'per_page' => 1]);
    }

    public function getVendorProducts(array $params = []): array
    {
        return $this->request(
            'GET',
            '/v1/vendors/' . $this->vendorId . '/products',
            $params
        );
    }

    public function getProduct(int $productId, bool $full = true): array
    {
        $headers = $full ? ['Prefer: return=full'] : ['Prefer: return=minimal'];
        return $this->request('GET', '/v1/products/' . $productId, [], null, $headers);
    }

    public function createProduct(array $data): array
    {
        return $this->request(
            'POST',
            '/v1/vendors/' . $this->vendorId . '/products',
            [],
            $data
        );
    }

    public function updateProduct(int $productId, array $data): array
    {
        // Basalam does not allow changing category_id after a product is created.
        // Existing Woo→Basalam mappings may use a newer Woo category mapping, so
        // never send category_id on PATCH; creation still sends it normally.
        unset($data['category_id']);

        $result = $this->request('PATCH', '/v1/products/' . $productId, [], $data);

        // Some legacy Basalam catalogs contain an old/inactive product that still
        // owns the Woo SKU. For an already-mapped Basalam product, do not let that
        // stale SKU block price/stock/content/image updates. Retry the same PATCH
        // once without SKU. Creation is intentionally not affected by this rule.
        if (
            $result['status'] === 422
            && !empty($result['error'])
            && array_key_exists('sku', $data)
            && trim((string)$data['sku']) !== ''
            && str_contains((string)$result['error'], 'sku')
            && str_contains((string)$result['error'], 'یکتا')
        ) {
            $retryData = $data;
            $conflictingSku = trim((string)$retryData['sku']);
            unset($retryData['sku']);

            $retry = $this->request('PATCH', '/v1/products/' . $productId, [], $retryData);
            if (!$retry['error']) {
                logActivity(
                    'basalam_update_sku_conflict',
                    'product:' . $productId,
                    sprintf(
                        'Basalam #%d updated without duplicate SKU %s after 422 uniqueness conflict',
                        $productId,
                        $conflictingSku
                    )
                );
                $retry['sku_conflict_ignored'] = true;
                $retry['conflicting_sku'] = $conflictingSku;
                return $retry;
            }
        }

        return $result;
    }

    public function updateProductVariation(int $productId, int $variationId, array $data): array
    {
        return $this->request(
            'PATCH',
            '/v1/products/' . $productId . '/variations/' . $variationId,
            [],
            $data
        );
    }

    public function updateBulkProducts(array $data): array
    {
        return $this->request(
            'PATCH',
            '/v1/vendors/' . $this->vendorId . '/products/batch-updates',
            [],
            ['data' => array_values($data)]
        );
    }

    public function getCategories(): array
    {
        return $this->request('GET', '/v1/categories');
    }

    public function getCategory(int $categoryId): array
    {
        return $this->request('GET', '/v1/categories/' . $categoryId);
    }

    public function getCategoryAttributes(
        int $categoryId,
        ?int $productId = null,
        bool $excludeMultiSelects = true
    ): array {
        $params = [
            'exclude_multi_selects' => $excludeMultiSelects ? 'true' : 'false',
        ];
        if ($productId !== null) {
            $params['product_id'] = $productId;
        }
        if ($this->vendorId > 0) {
            $params['vendor_id'] = $this->vendorId;
        }

        return $this->request(
            'GET',
            '/v1/categories/' . $categoryId . '/attributes',
            $params
        );
    }

    public function uploadFile(string $filePath, string $fileType = 'product.photo'): array
    {
        if (!$this->isConfigured()) {
            return $this->failure('اتصال باسلام تنظیم نشده است.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            return $this->failure('فایل برای آپلود باسلام در دسترس نیست.');
        }

        $token = $this->getAccessToken();
        if ($token['error'] !== null) {
            return $this->failure($token['error']);
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : false;
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';

        $ch = curl_init();
        if ($ch === false) {
            return $this->failure('راه‌اندازی cURL ناموفق بود.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/v1/files',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file_type' => $fileType,
                'file' => new CURLFile($filePath, $mime, basename($filePath)),
            ],
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token['token'],
            ],
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        return $this->execute($ch);
    }

    public function uploadRemoteImage(string $url): array
    {
        $tmp = $this->downloadPublicRemoteFile($url, 12 * 1024 * 1024);
        if ($tmp['error'] !== null) {
            return $this->failure($tmp['error']);
        }

        try {
            return $this->uploadFile($tmp['path'], 'product.photo');
        } finally {
            @unlink($tmp['path']);
        }
    }

    private function request(
        string $method,
        string $path,
        array $params = [],
        ?array $body = null,
        array $extraHeaders = [],
        bool $retryAuth = true
    ): array {
        if (!$this->isConfigured()) {
            return $this->failure('اتصال باسلام تنظیم نشده است.');
        }

        $token = $this->getAccessToken();
        if ($token['error'] !== null) {
            return $this->failure($token['error']);
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $headers = array_merge([
            'Accept: application/json',
            'Authorization: Bearer ' . $token['token'],
        ], $extraHeaders);

        $ch = curl_init();
        if ($ch === false) {
            return $this->failure('راه‌اندازی cURL ناموفق بود.');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($ch);
                return $this->failure('تبدیل درخواست باسلام به JSON ناموفق بود.');
            }
            $options[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $options);
        $result = $this->execute($ch);

        if (
            $retryAuth
            && $result['status'] === 401
            && $this->authMode === 'client_credentials'
        ) {
            $this->runtimeToken = null;
            $this->runtimeTokenExpiresAt = 0;
            return $this->request($method, $path, $params, $body, $extraHeaders, false);
        }

        return $result;
    }

    private function execute($ch): array
    {
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return $this->failure('خطای اتصال به باسلام: ' . ($curlError ?: 'خطای نامشخص'));
        }

        $rawHeaders = substr($response, 0, $headerLen);
        $rawBody = substr($response, $headerLen);
        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);

        if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => $status,
                'body' => [],
                'headers' => [],
                'error' => 'پاسخ باسلام JSON معتبر نیست.',
            ];
        }

        $decoded = is_array($decoded) ? $this->unwrapResponse($decoded) : [];
        $error = null;
        if ($status < 200 || $status >= 300) {
            $error = $this->extractApiError($decoded, $status);
        }

        return [
            'status' => $status,
            'body' => $decoded,
            'headers' => $this->parseHeaders($rawHeaders),
            'error' => $error,
        ];
    }

    private function getAccessToken(): array
    {
        if ($this->authMode !== 'client_credentials') {
            if ($this->accessToken === '') {
                return ['token' => '', 'error' => 'Access Token باسلام تنظیم نشده است.'];
            }
            return ['token' => $this->accessToken, 'error' => null];
        }

        if (
            $this->runtimeToken !== null
            && $this->runtimeToken !== ''
            && $this->runtimeTokenExpiresAt > time() + 30
        ) {
            return ['token' => $this->runtimeToken, 'error' => null];
        }

        $ch = curl_init();
        if ($ch === false) {
            return ['token' => '', 'error' => 'راه‌اندازی cURL برای احراز هویت باسلام ناموفق بود.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => $this->scopes,
            ]),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['token' => '', 'error' => 'خطای دریافت توکن باسلام: ' . ($curlError ?: 'خطای نامشخص')];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['token' => '', 'error' => 'پاسخ احراز هویت باسلام نامعتبر است.'];
        }

        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            return [
                'token' => '',
                'error' => $data['error_description']
                    ?? $data['message']
                    ?? $data['error']
                    ?? ('خطای HTTP ' . $status . ' در دریافت توکن باسلام'),
            ];
        }

        $this->runtimeToken = (string)$data['access_token'];
        $expiresIn = max(60, (int)($data['expires_in'] ?? 3600));
        $this->runtimeTokenExpiresAt = time() + $expiresIn;

        return ['token' => $this->runtimeToken, 'error' => null];
    }

    private function unwrapResponse(array $data): array
    {
        if (!array_key_exists('response', $data)) {
            return $data;
        }

        $inner = $data['response'];
        if (is_array($inner)) {
            return $inner;
        }
        if (is_string($inner)) {
            $decoded = json_decode($inner, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $data;
    }

    private function extractApiError(array $body, int $status): string
    {
        foreach (['message', 'error_description', 'error', 'detail'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        if (!empty($body['messages']) && is_array($body['messages'])) {
            $messages = [];
            foreach ($body['messages'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $message = trim((string)($item['message'] ?? ''));
                if ($message === '') {
                    continue;
                }
                $fields = array_values(array_filter(array_map(
                    static fn($field) => trim((string)$field),
                    (array)($item['fields'] ?? [])
                )));
                $messages[] = $fields ? implode(', ', $fields) . ': ' . $message : $message;
            }
            if ($messages) {
                return implode(' | ', $messages);
            }
        }

        if (!empty($body['errors']) && is_array($body['errors'])) {
            $encoded = json_encode($body['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return $status > 0 ? 'خطای HTTP ' . $status . ' از باسلام' : 'خطای نامشخص باسلام';
    }

    private function parseHeaders(string $rawHeaders): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($rawHeaders)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            if ($name !== '') {
                $result[strtolower($name)] = $value;
            }
        }
        return $result;
    }

    private function downloadPublicRemoteFile(string $url, int $maxBytes): array
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
        ) {
            return ['path' => '', 'error' => 'آدرس تصویر معتبر نیست.'];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['path' => '', 'error' => 'آدرس تصویر نباید شامل اطلاعات ورود باشد.'];
        }

        $scheme = strtolower((string)$parts['scheme']);
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            return ['path' => '', 'error' => 'پورت آدرس تصویر مجاز نیست.'];
        }

        $host = (string)$parts['host'];
        $ips = gethostbynamel($host);
        if (!$ips) {
            return ['path' => '', 'error' => 'دامنه تصویر قابل resolve نیست.'];
        }

        foreach ($ips as $ip) {
            if (
                filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                return ['path' => '', 'error' => 'دانلود تصویر از آدرس خصوصی/رزرو شده مجاز نیست.'];
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'basalam_img_');
        if ($tmpPath === false) {
            return ['path' => '', 'error' => 'ساخت فایل موقت برای تصویر ناموفق بود.'];
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            return ['path' => '', 'error' => 'باز کردن فایل موقت ناموفق بود.'];
        }

        $downloaded = 0;
        $tooLarge = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            // Pin DNS to the already-validated public IP to prevent DNS rebinding.
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            // Never follow redirects here: a public URL could redirect to a private/reserved IP (SSRF).
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($fp, $maxBytes, &$downloaded, &$tooLarge) {
                $length = strlen($chunk);
                $downloaded += $length;
                if ($downloaded > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                return fwrite($fp, $chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($tmpPath);
            return [
                'path' => '',
                'error' => $tooLarge
                    ? 'حجم تصویر بیشتر از حد مجاز داخلی (۱۲ مگابایت) است.'
                    : 'دانلود تصویر برای باسلام ناموفق بود: ' . ($error ?: ('HTTP ' . $status)),
            ];
        }

        return ['path' => $tmpPath, 'error' => null];
    }

    private function failure(string $message, int $status = 0): array
    {
        return ['status' => $status, 'body' => [], 'headers' => [], 'error' => $message];
    }
}
