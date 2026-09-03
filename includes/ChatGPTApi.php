<?php

/**
 * Shared helpers for the ChatGPT-facing API.
 *
 * Authentication is intentionally independent from browser sessions/CSRF:
 * callers must send Authorization: Bearer <token>. Only the SHA-256 hash is
 * stored in the settings table when the token is generated from the admin UI.
 */
function chatgptApiTokenHash(): string
{
    $envToken = getenv('WC_MANAGER_API_TOKEN');
    if (is_string($envToken) && trim($envToken) !== '') {
        return hash('sha256', trim($envToken));
    }

    return trim((string)getSetting('chatgpt_api_token_hash', ''));
}

function chatgptApiHasToken(): bool
{
    return chatgptApiTokenHash() !== '';
}

function chatgptApiAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function requireChatgptApiAuth(): void
{
    $expectedHash = chatgptApiTokenHash();
    if ($expectedHash === '') {
        jsonResponse([
            'success' => false,
            'error' => 'api_not_configured',
            'message' => 'ChatGPT API token is not configured.',
        ], 503);
    }

    $header = chatgptApiAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        header('WWW-Authenticate: Bearer');
        jsonResponse([
            'success' => false,
            'error' => 'unauthorized',
            'message' => 'A Bearer token is required.',
        ], 401);
    }

    $token = trim((string)$match[1]);
    if ($token === '' || !hash_equals($expectedHash, hash('sha256', $token))) {
        header('WWW-Authenticate: Bearer');
        jsonResponse([
            'success' => false,
            'error' => 'unauthorized',
            'message' => 'Invalid API token.',
        ], 401);
    }
}

function apiRequestMethod(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function apiRequireMethods(array $methods): string
{
    $method = apiRequestMethod();
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        jsonResponse([
            'success' => false,
            'error' => 'method_not_allowed',
            'message' => 'Method not allowed.',
        ], 405);
    }
    return $method;
}

function apiJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse([
            'success' => false,
            'error' => 'invalid_json',
            'message' => 'Request body must be valid JSON.',
        ], 400);
    }

    return $decoded;
}

function apiPositiveInt($value, string $name): int
{
    $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($int === false) {
        jsonResponse([
            'success' => false,
            'error' => 'invalid_parameter',
            'message' => $name . ' must be a positive integer.',
        ], 400);
    }
    return (int)$int;
}

function apiFilterArray(array $data, array $allowedKeys): array
{
    return array_intersect_key($data, array_flip($allowedKeys));
}

function apiWooResponse(array $response, int $successStatus = 200): void
{
    if (!empty($response['error'])) {
        $status = (int)($response['status'] ?? 0);
        if ($status < 400 || $status > 599) {
            $status = 502;
        }

        jsonResponse([
            'success' => false,
            'error' => 'woocommerce_error',
            'message' => (string)$response['error'],
            'upstream_status' => (int)($response['status'] ?? 0),
        ], $status);
    }

    $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
    jsonResponse([
        'success' => true,
        'data' => $response['body'] ?? [],
        'meta' => [
            'total' => $headers['total'] ?? null,
            'total_pages' => $headers['total_pages'] ?? null,
        ],
    ], $successStatus);
}

function apiLogActivity(string $action, string $target = '', string $details = ''): void
{
    logActivity($action, $target, $details);
}

function apiProductAllowedFields(): array
{
    return [
        'name', 'slug', 'type', 'status', 'featured', 'catalog_visibility',
        'description', 'short_description', 'sku', 'regular_price', 'sale_price',
        'date_on_sale_from', 'date_on_sale_to', 'virtual', 'downloadable',
        'downloads', 'download_limit', 'download_expiry', 'external_url',
        'button_text', 'tax_status', 'tax_class', 'manage_stock', 'stock_quantity',
        'stock_status', 'backorders', 'sold_individually', 'weight', 'dimensions',
        'shipping_class', 'reviews_allowed', 'upsell_ids', 'cross_sell_ids',
        'parent_id', 'purchase_note', 'categories', 'tags', 'images', 'attributes',
        'default_attributes', 'menu_order', 'meta_data',
    ];
}

function apiVariationAllowedFields(): array
{
    return [
        'description', 'sku', 'regular_price', 'sale_price', 'date_on_sale_from',
        'date_on_sale_to', 'virtual', 'downloadable', 'downloads',
        'download_limit', 'download_expiry', 'tax_status', 'tax_class',
        'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'weight',
        'dimensions', 'shipping_class', 'image', 'attributes', 'menu_order',
        'meta_data',
    ];
}
