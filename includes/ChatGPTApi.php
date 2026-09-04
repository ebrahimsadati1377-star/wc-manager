<?php

/**
 * Shared helpers for the ChatGPT-facing API.
 *
 * External callers authenticate with Authorization: Bearer <token>.
 * New installations may keep multiple independently revocable token hashes in
 * the settings table. The legacy single-token hash and WC_MANAGER_API_TOKEN
 * environment token remain valid for backwards compatibility.
 */
function chatgptApiTruncateText(string $value, int $maxChars): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxChars, 'UTF-8');
    }
    if (preg_match_all('/./us', $value, $matches) !== false) {
        return implode('', array_slice($matches[0], 0, $maxChars));
    }
    return substr($value, 0, $maxChars);
}

function chatgptApiStoredTokens(): array
{
    $raw = trim((string)getSetting('chatgpt_api_tokens', ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $tokens = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $hash = trim((string)($row['hash'] ?? ''));
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{4,80}$/', $id) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            continue;
        }
        $tokens[] = [
            'id' => $id,
            'name' => $name !== '' ? chatgptApiTruncateText($name, 80) : 'API client',
            'hash' => strtolower($hash),
            'created_at' => trim((string)($row['created_at'] ?? '')),
            'last4' => preg_replace('/[^A-Za-z0-9]/', '', (string)($row['last4'] ?? '')),
        ];
    }
    return $tokens;
}

function chatgptApiSaveStoredTokens(array $tokens): void
{
    setSetting('chatgpt_api_tokens', json_encode(array_values($tokens), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function chatgptApiCreateToken(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('برای توکن یک نام وارد کنید.');
    }

    $tokens = chatgptApiStoredTokens();
    if (count($tokens) >= 25) {
        throw new RuntimeException('حداکثر ۲۵ توکن فعال مجاز است. ابتدا یکی از توکن‌ها را باطل کنید.');
    }

    $plain = 'wcm_' . bin2hex(random_bytes(32));
    $record = [
        'id' => 'tok_' . bin2hex(random_bytes(6)),
        'name' => chatgptApiTruncateText($name, 80),
        'hash' => hash('sha256', $plain),
        'created_at' => gmdate('c'),
        'last4' => substr($plain, -4),
    ];
    $tokens[] = $record;
    chatgptApiSaveStoredTokens($tokens);

    return ['token' => $plain, 'record' => $record];
}

function chatgptApiRevokeStoredToken(string $id): bool
{
    $tokens = chatgptApiStoredTokens();
    $next = [];
    $found = false;
    foreach ($tokens as $token) {
        if (hash_equals((string)$token['id'], $id)) {
            $found = true;
            continue;
        }
        $next[] = $token;
    }
    if ($found) {
        chatgptApiSaveStoredTokens($next);
    }
    return $found;
}

function chatgptApiTokenRecords(): array
{
    $records = [];

    $envToken = getenv('WC_MANAGER_API_TOKEN');
    if (is_string($envToken) && trim($envToken) !== '') {
        $records[] = [
            'id' => 'environment',
            'name' => 'Environment token',
            'hash' => hash('sha256', trim($envToken)),
        ];
    }

    foreach (chatgptApiStoredTokens() as $row) {
        $records[] = $row;
    }

    $legacyHash = trim((string)getSetting('chatgpt_api_token_hash', ''));
    if (preg_match('/^[a-f0-9]{64}$/i', $legacyHash)) {
        $records[] = [
            'id' => 'legacy',
            'name' => 'Legacy token',
            'hash' => strtolower($legacyHash),
        ];
    }

    $seen = [];
    $unique = [];
    foreach ($records as $row) {
        $hash = strtolower((string)($row['hash'] ?? ''));
        if ($hash === '' || isset($seen[$hash])) {
            continue;
        }
        $seen[$hash] = true;
        $unique[] = $row;
    }
    return $unique;
}

function chatgptApiTokenHash(): string
{
    // Backwards-compatible helper for callers that still expect one hash.
    $records = chatgptApiTokenRecords();
    return $records ? (string)$records[0]['hash'] : '';
}

function chatgptApiHasToken(): bool
{
    return count(chatgptApiTokenRecords()) > 0;
}

function chatgptApiCurrentTokenId(): string
{
    return (string)($GLOBALS['chatgpt_api_current_token_id'] ?? '');
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
    $records = chatgptApiTokenRecords();
    if (!$records) {
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
    $candidateHash = $token !== '' ? hash('sha256', $token) : '';
    $matchedId = '';
    foreach ($records as $row) {
        if ($candidateHash !== '' && hash_equals((string)$row['hash'], $candidateHash)) {
            $matchedId = (string)$row['id'];
            break;
        }
    }

    if ($matchedId === '') {
        header('WWW-Authenticate: Bearer');
        jsonResponse([
            'success' => false,
            'error' => 'unauthorized',
            'message' => 'Invalid API token.',
        ], 401);
    }

    $GLOBALS['chatgpt_api_current_token_id'] = $matchedId;
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
    $clientId = chatgptApiCurrentTokenId();
    if ($clientId !== '') {
        $details = trim($details . ($details !== '' ? ' | ' : '') . 'api_client=' . $clientId);
    }
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
