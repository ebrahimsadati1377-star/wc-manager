<?php

class WcManagerOAuthException extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }
}

class WcManagerOAuthService
{
    public const ISSUER = 'https://manage.bajistyle.ir';
    public const RESOURCE = 'https://manage.bajistyle.ir/mcp.php';
    public const CLIENT_METADATA = 'https://chatgpt.com/oauth/client.json';
    public const ACCESS_TOKEN_TTL = 3600;
    public const REFRESH_TOKEN_TTL = 2592000;
    public const AUTH_CODE_TTL = 300;
    public const AUDIT_RETENTION_DAYS = 90;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
        $this->ensureStorage();
        $this->cleanup();
    }

    public static function supportedScopes(): array
    {
        return [
            'store.read',
            'store.write',
            'media.write',
            'articles.read',
            'articles.write',
            'basalam.read',
            'basalam.write',
        ];
    }

    public static function toolScopes(string $tool): array
    {
        $map = [
            'check_connection' => ['store.read', 'basalam.read'],
            'search_products' => ['store.read'],
            'get_product' => ['store.read'],
            'create_product' => ['store.write'],
            'update_product' => ['store.write'],
            'list_categories' => ['store.read'],
            'upload_image' => ['media.write'],
            'attach_product_image' => ['store.write', 'media.write'],
            'upload_and_attach_product_image' => ['store.write', 'media.write'],
            'search_articles' => ['articles.read'],
            'get_article' => ['articles.read'],
            'create_article' => ['articles.write'],
            'update_article' => ['articles.write'],
            'list_basalam_products' => ['basalam.read'],
            'get_basalam_product' => ['basalam.read'],
            'update_basalam_product' => ['basalam.write'],
            'sync_basalam_product' => ['store.read', 'basalam.write'],
        ];
        return $map[$tool] ?? [];
    }

    public static function scopeLabels(): array
    {
        return [
            'store.read' => 'Read WooCommerce products, categories, prices and stock.',
            'store.write' => 'Create or modify WooCommerce products and product images.',
            'media.write' => 'Import images and copy them to the WordPress media library.',
            'articles.read' => 'Read WordPress posts and articles.',
            'articles.write' => 'Create or modify WordPress posts and articles.',
            'basalam.read' => 'Read products from the configured Basalam vendor.',
            'basalam.write' => 'Modify or synchronize products in the configured Basalam vendor.',
        ];
    }

    public function ensureStorage(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code_hash CHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                client_id VARCHAR(1024) NOT NULL,
                redirect_uri VARCHAR(1024) NOT NULL,
                scopes TEXT NOT NULL,
                resource VARCHAR(512) NOT NULL,
                code_challenge VARCHAR(128) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_oauth_code_hash (code_hash),
                KEY idx_oauth_code_expires (expires_at),
                KEY idx_oauth_code_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS oauth_access_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                client_id VARCHAR(1024) NOT NULL,
                scopes TEXT NOT NULL,
                resource VARCHAR(512) NOT NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_oauth_access_hash (token_hash),
                KEY idx_oauth_access_expires (expires_at),
                KEY idx_oauth_access_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                client_id VARCHAR(1024) NOT NULL,
                scopes TEXT NOT NULL,
                resource VARCHAR(512) NOT NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_oauth_refresh_hash (token_hash),
                KEY idx_oauth_refresh_expires (expires_at),
                KEY idx_oauth_refresh_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function cleanup(): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            $old = date('Y-m-d H:i:s', time() - self::AUDIT_RETENTION_DAYS * 86400);
            $this->db->prepare('DELETE FROM oauth_authorization_codes WHERE expires_at < :now OR used_at IS NOT NULL')->execute(['now' => $now]);
            $this->db->prepare('DELETE FROM oauth_access_tokens WHERE expires_at < :now OR revoked_at IS NOT NULL')->execute(['now' => $now]);
            $this->db->prepare('DELETE FROM oauth_refresh_tokens WHERE expires_at < :now OR revoked_at IS NOT NULL')->execute(['now' => $now]);
            $this->db->prepare("DELETE FROM activity_log WHERE created_at < :old AND (action LIKE 'mcp_%' OR action LIKE 'oauth_%')")->execute(['old' => $old]);
        } catch (Throwable $e) {
            error_log('[wc-manager] OAuth cleanup failed: ' . $e->getMessage());
        }
    }

    public function validateAuthorizationRequest(array $input): array
    {
        $responseType = trim((string)($input['response_type'] ?? ''));
        if ($responseType !== 'code') {
            throw new WcManagerOAuthException('unsupported_response_type', 'Only response_type=code is supported.');
        }

        $clientId = trim((string)($input['client_id'] ?? ''));
        $redirectUri = trim((string)($input['redirect_uri'] ?? ''));
        $resource = trim((string)($input['resource'] ?? ''));
        $codeChallenge = trim((string)($input['code_challenge'] ?? ''));
        $challengeMethod = strtoupper(trim((string)($input['code_challenge_method'] ?? '')));
        if ($clientId === '' || $redirectUri === '') {
            throw new WcManagerOAuthException('invalid_request', 'client_id and redirect_uri are required.');
        }
        if ($resource !== self::RESOURCE) {
            throw new WcManagerOAuthException('invalid_target', 'The requested resource is not supported.');
        }
        if ($challengeMethod !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $codeChallenge)) {
            throw new WcManagerOAuthException('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $metadata = $this->validateClientMetadata($clientId);
        $redirects = is_array($metadata['redirect_uris'] ?? null) ? $metadata['redirect_uris'] : [];
        if (!in_array($redirectUri, $redirects, true)) {
            throw new WcManagerOAuthException('invalid_request', 'redirect_uri is not registered by the client metadata document.');
        }

        $scopes = $this->normalizeScopes((string)($input['scope'] ?? ''));
        if (!$scopes) {
            throw new WcManagerOAuthException('invalid_scope', 'At least one supported scope is required.');
        }

        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'resource' => $resource,
            'scope' => implode(' ', $scopes),
            'scopes' => $scopes,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => (string)($input['state'] ?? ''),
        ];
    }

    public function validateClientMetadata(string $clientId): array
    {
        $parts = parse_url($clientId);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'chatgpt.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new WcManagerOAuthException('invalid_client', 'Only ChatGPT HTTPS client metadata documents are accepted.', 401);
        }

        $path = (string)($parts['path'] ?? '');
        if ($path !== '/oauth/client.json' && !preg_match('#^/oauth/[A-Za-z0-9._~-]{1,120}/client\.json$#', $path)) {
            throw new WcManagerOAuthException('invalid_client', 'Unsupported ChatGPT client metadata URL.', 401);
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new WcManagerOAuthException('invalid_client', 'Client metadata URL must not include query or fragment.', 401);
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new WcManagerOAuthException('server_error', 'Could not initialize client metadata validation.', 500);
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $clientId,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new WcManagerOAuthException('invalid_client', 'Could not validate ChatGPT client metadata: ' . ($error ?: ('HTTP ' . $status)), 401);
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || !hash_equals($clientId, trim((string)($data['client_id'] ?? '')))) {
            throw new WcManagerOAuthException('invalid_client', 'Client metadata document is invalid.', 401);
        }
        if (!is_array($data['redirect_uris'] ?? null) || !$data['redirect_uris']) {
            throw new WcManagerOAuthException('invalid_client', 'Client metadata has no redirect_uris.', 401);
        }
        return $data;
    }

    public function createAuthorizationCode(int $userId, array $request): string
    {
        $this->assertAdminUser($userId);
        $code = 'wcm_oauth_code_' . bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO oauth_authorization_codes
             (code_hash,user_id,client_id,redirect_uri,scopes,resource,code_challenge,expires_at)
             VALUES (:hash,:uid,:client,:redirect,:scopes,:resource,:challenge,:expires)'
        );
        $stmt->execute([
            'hash' => hash('sha256', $code),
            'uid' => $userId,
            'client' => $request['client_id'],
            'redirect' => $request['redirect_uri'],
            'scopes' => $request['scope'],
            'resource' => $request['resource'],
            'challenge' => $request['code_challenge'],
            'expires' => date('Y-m-d H:i:s', time() + self::AUTH_CODE_TTL),
        ]);
        logActivity('oauth_authorization_granted', 'user:' . $userId, 'client=chatgpt scopes=' . $request['scope']);
        return $code;
    }

    public function redeemAuthorizationCode(array $input): array
    {
        $code = trim((string)($input['code'] ?? ''));
        $clientId = trim((string)($input['client_id'] ?? ''));
        $redirectUri = trim((string)($input['redirect_uri'] ?? ''));
        $resource = trim((string)($input['resource'] ?? ''));
        $verifier = trim((string)($input['code_verifier'] ?? ''));
        if ($code === '' || $clientId === '' || $redirectUri === '' || $verifier === '') {
            throw new WcManagerOAuthException('invalid_request', 'code, client_id, redirect_uri and code_verifier are required.');
        }
        if ($resource !== self::RESOURCE) {
            throw new WcManagerOAuthException('invalid_target', 'The requested resource is not supported.');
        }
        $this->validateClientMetadata($clientId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM oauth_authorization_codes WHERE code_hash = :hash LIMIT 1 FOR UPDATE');
            $stmt->execute(['hash' => hash('sha256', $code)]);
            $row = $stmt->fetch();
            if (!is_array($row)
                || !empty($row['used_at'])
                || strtotime((string)$row['expires_at']) < time()
                || !hash_equals((string)$row['client_id'], $clientId)
                || !hash_equals((string)$row['redirect_uri'], $redirectUri)
                || !hash_equals((string)$row['resource'], $resource)) {
                throw new WcManagerOAuthException('invalid_grant', 'Authorization code is invalid or expired.');
            }
            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
                throw new WcManagerOAuthException('invalid_grant', 'Invalid PKCE code_verifier.');
            }
            $derived = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!hash_equals((string)$row['code_challenge'], $derived)) {
                throw new WcManagerOAuthException('invalid_grant', 'PKCE verification failed.');
            }
            $this->assertAdminUser((int)$row['user_id']);
            $this->db->prepare('UPDATE oauth_authorization_codes SET used_at = NOW() WHERE id = :id')->execute(['id' => $row['id']]);
            $tokens = $this->issueTokenPair((int)$row['user_id'], $clientId, (string)$row['scopes'], $resource);
            $this->db->commit();
            logActivity('oauth_token_issued', 'user:' . (int)$row['user_id'], 'client=chatgpt');
            return $tokens;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function refreshAccessToken(array $input): array
    {
        $refreshToken = trim((string)($input['refresh_token'] ?? ''));
        $clientId = trim((string)($input['client_id'] ?? ''));
        $resource = trim((string)($input['resource'] ?? self::RESOURCE));
        if ($refreshToken === '' || $clientId === '') {
            throw new WcManagerOAuthException('invalid_request', 'refresh_token and client_id are required.');
        }
        if ($resource !== self::RESOURCE) {
            throw new WcManagerOAuthException('invalid_target', 'The requested resource is not supported.');
        }
        $this->validateClientMetadata($clientId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM oauth_refresh_tokens WHERE token_hash = :hash LIMIT 1 FOR UPDATE');
            $stmt->execute(['hash' => hash('sha256', $refreshToken)]);
            $row = $stmt->fetch();
            if (!is_array($row)
                || !empty($row['revoked_at'])
                || strtotime((string)$row['expires_at']) < time()
                || !hash_equals((string)$row['client_id'], $clientId)
                || !hash_equals((string)$row['resource'], $resource)) {
                throw new WcManagerOAuthException('invalid_grant', 'Refresh token is invalid or expired.');
            }
            $this->assertAdminUser((int)$row['user_id']);
            $this->db->prepare('UPDATE oauth_refresh_tokens SET revoked_at = NOW() WHERE id = :id')->execute(['id' => $row['id']]);
            $tokens = $this->issueTokenPair((int)$row['user_id'], $clientId, (string)$row['scopes'], $resource);
            $this->db->commit();
            logActivity('oauth_token_refreshed', 'user:' . (int)$row['user_id'], 'client=chatgpt');
            return $tokens;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function authenticateAccessToken(string $plain): ?array
    {
        if ($plain === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT t.*, u.username, u.full_name, u.role, u.is_active
             FROM oauth_access_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $plain)]);
        $row = $stmt->fetch();
        if (!is_array($row)
            || !empty($row['revoked_at'])
            || strtotime((string)$row['expires_at']) < time()
            || (int)$row['is_active'] !== 1
            || (string)$row['role'] !== 'admin'
            || !hash_equals((string)$row['resource'], self::RESOURCE)) {
            return null;
        }
        $row['scope_list'] = $this->normalizeScopes((string)$row['scopes']);
        return $row;
    }

    public function hasScopes(array $token, array $required): bool
    {
        if (!$required) {
            return true;
        }
        $granted = is_array($token['scope_list'] ?? null)
            ? $token['scope_list']
            : $this->normalizeScopes((string)($token['scopes'] ?? ''));
        return count(array_diff($required, $granted)) === 0;
    }

    public function revoke(string $plain): bool
    {
        if ($plain === '') {
            return false;
        }
        $hash = hash('sha256', $plain);
        $access = $this->db->prepare('UPDATE oauth_access_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL');
        $access->execute(['hash' => $hash]);
        $refresh = $this->db->prepare('UPDATE oauth_refresh_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL');
        $refresh->execute(['hash' => $hash]);
        return $access->rowCount() > 0 || $refresh->rowCount() > 0;
    }

    private function issueTokenPair(int $userId, string $clientId, string $scopes, string $resource): array
    {
        $access = 'wcm_oauth_at_' . bin2hex(random_bytes(32));
        $refresh = 'wcm_oauth_rt_' . bin2hex(random_bytes(32));
        $accessExpires = date('Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL);
        $refreshExpires = date('Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL);
        $stmt = $this->db->prepare(
            'INSERT INTO oauth_access_tokens (token_hash,user_id,client_id,scopes,resource,expires_at)
             VALUES (:hash,:uid,:client,:scopes,:resource,:expires)'
        );
        $stmt->execute([
            'hash' => hash('sha256', $access),
            'uid' => $userId,
            'client' => $clientId,
            'scopes' => $scopes,
            'resource' => $resource,
            'expires' => $accessExpires,
        ]);
        $stmt = $this->db->prepare(
            'INSERT INTO oauth_refresh_tokens (token_hash,user_id,client_id,scopes,resource,expires_at)
             VALUES (:hash,:uid,:client,:scopes,:resource,:expires)'
        );
        $stmt->execute([
            'hash' => hash('sha256', $refresh),
            'uid' => $userId,
            'client' => $clientId,
            'scopes' => $scopes,
            'resource' => $resource,
            'expires' => $refreshExpires,
        ]);
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $refresh,
            'scope' => $scopes,
            'resource' => $resource,
        ];
    }

    private function normalizeScopes(string $scope): array
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
        $supported = self::supportedScopes();
        foreach ($parts as $item) {
            if (!in_array($item, $supported, true)) {
                throw new WcManagerOAuthException('invalid_scope', 'Unsupported scope: ' . $item);
            }
        }
        return $parts;
    }

    private function assertAdminUser(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT id, role, is_active FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!is_array($user) || (int)$user['is_active'] !== 1 || (string)$user['role'] !== 'admin') {
            throw new WcManagerOAuthException('access_denied', 'Only an active WC Manager administrator can authorize this plugin.', 403);
        }
    }
}
