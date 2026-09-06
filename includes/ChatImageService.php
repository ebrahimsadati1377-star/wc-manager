<?php

class ChatImageException extends RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(string $errorCode, string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }
}

class ChatImageService
{
    private const MAX_BYTES = 10485760; // 10 MiB
    private const MAX_REDIRECTS = 3;

    private string $uploadDir;

    public function __construct(?string $uploadDir = null)
    {
        $this->uploadDir = $uploadDir ?? dirname(__DIR__) . '/public/uploads/chatgpt';
    }

    /**
     * Import a single image from a ChatGPT conversation file reference, public URL,
     * or Base64 payload. Returns public metadata plus a private local_path key for
     * callers that need to copy the file into WordPress or another upstream.
     *
     * @throws ChatImageException
     */
    public function import(array $input): array
    {
        $filename = trim((string)($input['filename'] ?? ''));
        $base64 = (string)($input['base64'] ?? '');
        $sourceUrl = trim((string)($input['url'] ?? $input['image_url'] ?? ''));
        $fileRefs = $input['openaiFileIdRefs'] ?? [];

        if (!is_array($fileRefs)) {
            throw new ChatImageException('validation_error', 'openaiFileIdRefs must be an array.');
        }
        if (count($fileRefs) > 1) {
            throw new ChatImageException('validation_error', 'Exactly one ChatGPT file reference may be uploaded at a time.');
        }

        if ($sourceUrl === '' && $base64 === '' && $fileRefs !== []) {
            $firstRef = $fileRefs[0] ?? null;
            if (!is_array($firstRef)) {
                throw new ChatImageException('invalid_file_ref', 'Invalid ChatGPT file reference.');
            }

            $sourceUrl = trim((string)($firstRef['download_link'] ?? ''));
            if ($filename === '') {
                $filename = trim((string)($firstRef['name'] ?? ''));
            }

            if ($sourceUrl === '') {
                throw new ChatImageException('invalid_file_ref', 'ChatGPT file reference is missing download_link.');
            }
        }

        if ($filename === '') {
            $filename = 'chatgpt-image';
        }

        if ($base64 === '' && $sourceUrl === '') {
            throw new ChatImageException(
                'validation_error',
                'Provide openaiFileIdRefs, url/image_url, or base64 image data.'
            );
        }

        $binary = $base64 !== '' ? $this->decodeBase64($base64) : $this->downloadPublicImage($sourceUrl);

        if (strlen($binary) > self::MAX_BYTES) {
            throw new ChatImageException('file_too_large', 'Maximum image size is 10 MB.', 413);
        }

        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false) {
            throw new ChatImageException('invalid_image', 'Downloaded/uploaded content is not a valid image.');
        }

        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowedTypes[$mime])) {
            throw new ChatImageException('unsupported_image_type', 'Only JPEG, PNG and WebP images are supported.');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($filename, PATHINFO_FILENAME));
        $safeBase = trim((string)$safeBase, '-_.');
        if ($safeBase === '') {
            $safeBase = 'image';
        }

        $safeName = $safeBase
            . '-' . gmdate('Ymd-His')
            . '-' . bin2hex(random_bytes(3))
            . '.' . $allowedTypes[$mime];

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            throw new ChatImageException('upload_directory_error', 'Could not create upload directory.', 500);
        }

        $path = rtrim($this->uploadDir, '/') . '/' . $safeName;
        if (file_put_contents($path, $binary, LOCK_EX) === false) {
            throw new ChatImageException('upload_failed', 'Could not save image.', 500);
        }
        @chmod($path, 0644);

        return [
            'success' => true,
            'url' => $this->publicUrl($safeName),
            'filename' => $safeName,
            'content_type' => $mime,
            'size' => strlen($binary),
            'width' => (int)$imageInfo[0],
            'height' => (int)$imageInfo[1],
            'local_path' => $path,
        ];
    }

    private function decodeBase64(string $base64): string
    {
        if (preg_match('/^data:[^;]+;base64,(.*)$/s', $base64, $match)) {
            $base64 = $match[1];
        }
        if (strlen($base64) > (int)ceil(self::MAX_BYTES * 4 / 3) + 4096) {
            throw new ChatImageException('file_too_large', 'Maximum image size is 10 MB.', 413);
        }
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new ChatImageException('invalid_base64', 'Invalid image data.');
        }
        return $binary;
    }

    /**
     * Downloads only from validated public hosts. Each redirect is re-validated
     * and DNS is pinned to a checked public IP to prevent SSRF/DNS rebinding.
     */
    private function downloadPublicImage(string $sourceUrl): string
    {
        $url = $sourceUrl;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->validateRemoteUrl($url);
            $body = '';
            $location = '';
            $downloaded = 0;
            $tooLarge = false;

            $ch = curl_init();
            if ($ch === false) {
                throw new ChatImageException('download_failed', 'Could not initialize image download.', 500);
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.5'],
                CURLOPT_USERAGENT => 'BAJI-WC-Manager/1.3 MCP',
                CURLOPT_RESOLVE => [$target['resolve']],
                CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$location): int {
                    if (stripos($line, 'Location:') === 0) {
                        $location = trim(substr($line, strlen('Location:')));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$downloaded, &$tooLarge): int {
                    $length = strlen($chunk);
                    $downloaded += $length;
                    if ($downloaded > self::MAX_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return $length;
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            $ok = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($tooLarge) {
                throw new ChatImageException('file_too_large', 'Maximum image size is 10 MB.', 413);
            }
            if ($ok === false) {
                throw new ChatImageException('download_failed', 'Could not download image: ' . ($error ?: 'network error'));
            }
            if ($status >= 200 && $status < 300) {
                if ($body === '') {
                    throw new ChatImageException('download_failed', 'The image response was empty.');
                }
                return $body;
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new ChatImageException('download_failed', 'Image URL exceeded the redirect limit.');
                }
                $url = $this->resolveRedirectUrl($url, $location);
                continue;
            }
            throw new ChatImageException('download_failed', 'Could not download image URL (HTTP ' . $status . ').');
        }

        throw new ChatImageException('download_failed', 'Could not download image URL.');
    }

    private function validateRemoteUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            throw new ChatImageException('invalid_url', 'A public http/https image URL is required.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ChatImageException('invalid_url', 'Image URLs may not contain login information.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($host === '' || !in_array($port, [80, 443], true)) {
            throw new ChatImageException('invalid_url', 'Image host or port is not allowed.');
        }
        if ($host === 'localhost' || preg_match('/(^|\.)local$/', $host)) {
            throw new ChatImageException('invalid_url', 'Local URLs are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                throw new ChatImageException('invalid_url', 'Private or reserved network URLs are not allowed.');
            }
            $ip = $host;
        } else {
            if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
                throw new ChatImageException('invalid_url', 'Image hostname is invalid.');
            }
            $ips = gethostbynamel($host);
            if (!$ips) {
                throw new ChatImageException('invalid_url', 'Image hostname could not be resolved.');
            }
            foreach ($ips as $resolved) {
                if (!$this->isPublicIp($resolved)) {
                    throw new ChatImageException('invalid_url', 'Image hostname resolves to a private or reserved network.');
                }
            }
            $ip = $ips[0];
        }

        return [
            'host' => $host,
            'port' => $port,
            'ip' => $ip,
            'resolve' => $host . ':' . $port . ':' . $ip,
        ];
    }

    private function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new ChatImageException('download_failed', 'Redirect location is empty.');
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            throw new ChatImageException('download_failed', 'Could not resolve image redirect.');
        }
        $scheme = strtolower((string)$baseParts['scheme']);
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $authority = $scheme . '://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $authority .= ':' . (int)$baseParts['port'];
        }
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        $basePath = (string)($baseParts['path'] ?? '/');
        $dir = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
        $path = $dir . $location;
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $query] = explode('?', $path, 2);
            $query = '?' . $query;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return $authority . '/' . implode('/', $segments) . $query;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function publicUrl(string $filename): string
    {
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME'] ?? 'https'));
        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = 'https';
        }

        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'manage.bajistyle.ir');
        $host = trim(explode(',', $host)[0]);
        if (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host)) {
            $host = 'manage.bajistyle.ir';
        }

        return $proto . '://' . $host . '/uploads/chatgpt/' . rawurlencode($filename);
    }
}
