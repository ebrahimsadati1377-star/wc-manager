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

        if ($base64 !== '') {
            $binary = $this->decodeBase64($base64);
        } else {
            $binary = $this->downloadPublicImage($sourceUrl);
        }

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
            throw new ChatImageException(
                'unsupported_image_type',
                'Only JPEG, PNG and WebP images are supported.'
            );
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
            throw new ChatImageException(
                'upload_directory_error',
                'Could not create upload directory.',
                500
            );
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

    private function downloadPublicImage(string $sourceUrl): string
    {
        $parts = parse_url($sourceUrl);
        if (
            !$parts
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
        ) {
            throw new ChatImageException('invalid_url', 'A public http/https image URL is required.');
        }

        $host = strtolower((string)$parts['host']);
        if (
            $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || preg_match('/(^|\\.)local$/', $host)
        ) {
            throw new ChatImageException('invalid_url', 'Local URLs are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && !$this->isPublicIp($host)) {
            throw new ChatImageException('invalid_url', 'Private or reserved network URLs are not allowed.');
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'BAJI-WC-Manager/1.2 MCP',
                'ignore_errors' => false,
            ],
            'https' => [
                'timeout' => 15,
            ],
        ]);

        $binary = @file_get_contents($sourceUrl, false, $context, 0, self::MAX_BYTES + 1);
        if ($binary === false || $binary === '') {
            throw new ChatImageException(
                'download_failed',
                'Could not download image URL or ChatGPT file reference.'
            );
        }

        return $binary;
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool)filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($lower === '::1' || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                return false;
            }
            if (preg_match('/^fe[89ab][0-9a-f]:/i', $lower)) {
                return false;
            }
            return true;
        }

        return false;
    }

    private function publicUrl(string $filename): string
    {
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME'] ?? 'https'));
        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = 'https';
        }

        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'manage.bajistyle.ir');
        $host = trim(explode(',', $host)[0]);
        if (!preg_match('/^[A-Za-z0-9.-]+(?::\\d{1,5})?$/', $host)) {
            $host = 'manage.bajistyle.ir';
        }

        return $proto . '://' . $host . '/uploads/chatgpt/' . rawurlencode($filename);
    }
}
