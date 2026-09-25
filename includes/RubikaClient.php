<?php

class RubikaClient
{
    private string $token;
    private string $channelId;
    private const API_BASE = 'https://botapi.rubika.ir/v3/';
    private const SHORT_BASE = 'https://manage.bajistyle.ir/r.php?';

    public function __construct(?string $token = null, ?string $channelId = null)
    {
        $this->token = trim($token ?? (string)getSetting('rubika_bot_token', ''));
        $this->channelId = trim($channelId ?? (string)getSetting('rubika_channel_id', ''));
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->channelId !== '';
    }

    public function getMe(): array
    {
        if ($this->token === '') {
            return $this->failure('توکن ربات روبیکا تنظیم نشده است.');
        }
        return $this->requestJson('getMe', []);
    }
    public function getRecentChats(): array
    {
        if ($this->token === '') {
            return $this->failure('توکن ربات روبیکا تنظیم نشده است.');
        }
        $updates = $this->requestJson('getUpdates', ['limit' => 100, 'offset_id' => '']);
        if (empty($updates['ok'])) {
            return $updates;
        }
        $items = is_array($updates['result']['updates'] ?? null) ? $updates['result']['updates'] : [];
        $chats = [];
        foreach ($items as $item) {
            $chatId = trim((string)($item['chat_id'] ?? ''));
            if ($chatId === '') continue;
            $chats[$chatId] = [
                'chat_id' => $chatId,
                'type' => (string)($item['type'] ?? ''),
                'text' => trim((string)($item['new_message']['text'] ?? '')),
            ];
        }
        return ['ok' => true, 'status' => (int)($updates['status'] ?? 200), 'result' => array_values($chats)];
    }

    public function sendMessage(string $text, ?string $chatId = null): array
    {
        $target = trim($chatId ?? $this->channelId);
        if ($this->token === '' || $target === '') {
            return $this->failure('توکن یا Chat ID روبیکا تنظیم نشده است.');
        }
        return $this->requestJson('sendMessage', [
            'chat_id' => $target,
            'text' => $text,
            'disable_notification' => false,
        ]);
    }

    public function sendProduct(array $product): array
    {
        if (!$this->isConfigured()) {
            return $this->failure('اتصال روبیکا کامل نیست.');
        }
        $caption = $this->buildProductCaption($product);
        $imageUrl = trim((string)($product['images'][0]['src'] ?? ''));
        if ($imageUrl !== '') {
            $photo = $this->sendImageFromUrl($imageUrl, $caption);
            if (!empty($photo['ok'])) {
                return $photo;
            }
        }
        return $this->sendMessage($caption);
    }

    public static function ensureWooWebhooks(?WooCommerceClient $wc = null): array
    {
        $wc = $wc ?? new WooCommerceClient();
        $secret = trim((string)getSetting('woocommerce_rubika_webhook_secret', ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            setSetting('woocommerce_rubika_webhook_secret', $secret);
        }
        $deliveryUrl = 'https://manage.bajistyle.ir/webhooks/woocommerce-rubika-sync.php';
        $list = $wc->get('webhooks', ['per_page' => 100]);
        if (!empty($list['error'])) {
            return ['success' => false, 'error' => $list['error']];
        }
        $existing = is_array($list['body'] ?? null) ? $list['body'] : [];
        $created = [];
        foreach (['product.created', 'product.updated'] as $topic) {
            $found = false;
            foreach ($existing as $hook) {
                if (($hook['topic'] ?? '') === $topic && ($hook['delivery_url'] ?? '') === $deliveryUrl) {
                    $found = true;
                    break;
                }
            }
            if ($found) continue;
            $res = $wc->post('webhooks', [
                'name' => 'BAJI Rubika ' . $topic,
                'status' => 'active',
                'topic' => $topic,
                'delivery_url' => $deliveryUrl,
                'secret' => $secret,
            ]);
            if (!empty($res['error'])) {
                return ['success' => false, 'error' => $res['error'], 'created' => $created];
            }
            $created[] = (int)($res['body']['id'] ?? 0);
        }
        return [
            'success' => true,
            'delivery_url' => $deliveryUrl,
            'created_webhook_ids' => array_values(array_filter($created)),
        ];
    }

    private function sendImageFromUrl(string $url, string $caption): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rubika_');
        if ($tmp === false) return $this->failure('ساخت فایل موقت عکس ناموفق بود.');
        try {
            $fp = fopen($tmp, 'wb');
            if ($fp === false) return $this->failure('فایل موقت قابل نوشتن نیست.');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'BAJI-WC-Manager/1.0',
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if ($ok === false || $status < 200 || $status >= 300 || filesize($tmp) === 0) {
                return $this->failure('دریافت عکس محصول ناموفق بود.');
            }
            $upload = $this->requestJson('requestSendFile', ['type' => 'Image']);
            if (empty($upload['ok'])) return $upload;
            $uploadUrl = trim((string)($upload['result']['upload_url'] ?? ''));
            if ($uploadUrl === '') return $this->failure('آدرس آپلود روبیکا دریافت نشد.');
            $fileId = $this->uploadFile($uploadUrl, $tmp);
            if ($fileId === '') return $this->failure('آپلود عکس در روبیکا ناموفق بود.');
            return $this->requestJson('sendFile', [
                'chat_id' => $this->channelId,
                'text' => mb_substr($caption, 0, 3000),
                'file_id' => $fileId,
                'disable_notification' => false,
            ]);
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function uploadFile(string $uploadUrl, string $path): string
    {
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $file = new CURLFile($path, $mime, 'product-image.jpg');
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => $file],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) return '';
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? trim((string)($decoded['data']['file_id'] ?? '')) : '';
    }

    private function requestJson(string $method, array $payload): array
    {
        $ch = curl_init(self::API_BASE . rawurlencode($this->token) . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) return $this->failure('خطای API روبیکا: ' . ($error ?: 'نامشخص'), $status);
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) return $this->failure('پاسخ روبیکا قابل خواندن نیست.', $status);
        $apiStatus = strtoupper(trim((string)($decoded['status'] ?? '')));
        if ($status < 200 || $status >= 300 || ($apiStatus !== '' && $apiStatus !== 'OK')) {
            $message = (string)($decoded['status_det'] ?? $decoded['message'] ?? $decoded['description'] ?? $decoded['status'] ?? ('HTTP ' . $status));
            return $this->failure($message, $status, $decoded);
        }
        return ['ok' => true, 'status' => $status, 'result' => $decoded['data'] ?? $decoded];
    }

    private function buildProductCaption(array $product): string
    {
        $name = trim($this->cleanText((string)($product['name'] ?? 'محصول جدید باجی')));
        $lines = ['🛍️ ' . $name, ''];
        $regular = (string)($product['regular_price'] ?? '');
        $sale = (string)($product['sale_price'] ?? '');
        if ($sale !== '' && (float)$sale > 0 && (float)$sale < (float)$regular) {
            $lines[] = 'قیمت اصلی: ' . $this->formatPrice($regular);
            $lines[] = 'قیمت با تخفیف: ' . $this->formatPrice($sale);
        } elseif ($regular !== '') {
            $lines[] = 'قیمت: ' . $this->formatPrice($regular);
        }

        $attributes = is_array($product['attributes'] ?? null) ? $product['attributes'] : [];
        $attrCount = 0;
        foreach ($attributes as $attribute) {
            $label = trim($this->cleanText((string)($attribute['name'] ?? '')));
            $options = $attribute['options'] ?? [];
            if (!is_array($options)) $options = [$options];
            $values = array_values(array_filter(array_map(fn($v) => trim($this->cleanText((string)$v)), $options)));
            if ($label === '' || !$values) continue;
            $lines[] = $label . ': ' . implode('، ', $values);
            if (++$attrCount >= 8) break;
        }

        $stock = strtolower((string)($product['stock_status'] ?? ''));
        if ($stock === 'instock') $lines[] = 'وضعیت: موجود';
        elseif ($stock === 'outofstock') $lines[] = 'وضعیت: ناموجود';

        $id = (int)($product['id'] ?? 0);
        $permalink = trim((string)($product['permalink'] ?? ''));
        $link = $id > 0 ? self::SHORT_BASE . $id : $this->utmLink($permalink, 0);
        if ($link !== '') {
            $lines[] = '';
            $lines[] = 'مشاهده و خرید:';
            $lines[] = $link;
        }
        $lines[] = '';
        $lines[] = 'باجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍';
        $lines[] = 'Baji Be your best';
        return mb_substr(implode("\n", $lines), 0, 3000);
    }

    private function utmLink(string $url, int $id): string
    {
        if ($url === '') return '';
        $params = http_build_query([
            'utm_source' => 'rubika',
            'utm_medium' => 'social',
            'utm_campaign' => 'product_channel',
            'utm_content' => $id > 0 ? 'product_' . $id : 'product',
        ]);
        return $url . (str_contains($url, '?') ? '&' : '?') . $params;
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/\s+/u', ' ', trim($value)) ?: '';
    }
    private function formatPrice($price): string
    {
        return number_format((float)$price, 0, '.', ',') . ' تومان';
    }

    private function failure(string $message, int $status = 0, array $raw = []): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $message, 'raw' => $raw];
    }
}