<?php

class TelegramClient
{
    private const API_BASE = 'https://api.telegram.org/bot';

    private string $token;
    private string $channel;

    public function __construct()
    {
        $this->token = trim((string)getSetting('telegram_bot_token', ''));
        $this->channel = trim((string)getSetting('telegram_channel_id', ''));
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->channel !== '';
    }

    public function getMe(): array
    {
        return $this->request('getMe', []);
    }

    public function sendMessage(string $text, ?string $chatId = null): array
    {
        return $this->request('sendMessage', [
            'chat_id' => $chatId ?: $this->channel,
            'text' => $text,
        ]);
    }

    public function sendProduct(array $product): array
    {
        $caption = $this->buildProductCaption($product);
        $imageUrl = trim((string)($product['images'][0]['src'] ?? ''));

        if ($imageUrl !== '') {
            $tmp = tempnam(sys_get_temp_dir(), 'telegram_');
            if ($tmp !== false) {
                $ch = curl_init($imageUrl);
                $fh = fopen($tmp, 'wb');
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fh,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                ]);
                $ok = curl_exec($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                fclose($fh);

                if ($ok !== false && $http >= 200 && $http < 300 && filesize($tmp) > 0) {
                    $mime = mime_content_type($tmp) ?: 'image/jpeg';
                    $res = $this->request('sendPhoto', [
                        'chat_id' => $this->channel,
                        'caption' => $caption,
                        'photo' => new CURLFile($tmp, $mime, 'product.jpg'),
                    ], true);
                    @unlink($tmp);
                    if (!empty($res['ok'])) {
                        return $res;
                    }
                } else {
                    @unlink($tmp);
                }
            }
        }

        return $this->sendMessage($caption);
    }

    private function buildProductCaption(array $product): string
    {
        $name = trim((string)($product['name'] ?? 'محصول باجی'));
        $regular = trim((string)($product['regular_price'] ?? ''));
        $sale = trim((string)($product['sale_price'] ?? ''));
        $permalink = trim((string)($product['permalink'] ?? ''));
        $trackedUrl = $this->buildTrackedProductUrl($permalink, (int)($product['id'] ?? 0));
        $stock = (string)($product['stock_status'] ?? '');

        $lines = ["✨ {$name}"];
        if ($regular !== '') {
            $lines[] = 'قیمت: ' . number_format((float)$regular) . ' تومان';
        }
        if ($sale !== '') {
            $lines[] = 'قیمت ویژه: ' . number_format((float)$sale) . ' تومان';
        }

        foreach (array_slice((array)($product['attributes'] ?? []), 0, 8) as $attr) {
            $label = trim((string)($attr['name'] ?? ''));
            $options = array_filter(array_map('trim', (array)($attr['options'] ?? [])));
            if ($label !== '' && $options) {
                $lines[] = $label . ': ' . implode('، ', $options);
            }
        }

        $lines[] = $stock === 'instock' ? '✅ موجود' : '⛔ ناموجود';

        $short = trim(strip_tags((string)($product['short_description'] ?? '')));
        if ($short !== '') {
            $lines[] = mb_substr((string)preg_replace('/\s+/u', ' ', $short), 0, 220);
        }

        if ($trackedUrl !== '') {
            $lines[] = $trackedUrl;
        }

        $lines[] = 'باجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍';
        $lines[] = 'Baji Be your best';

        return mb_substr(implode("\n", $lines), 0, 950);
    }

    private function buildTrackedProductUrl(string $url, int $productId): string
    {
        if ($productId > 0) {
            return 'https://manage.bajistyle.ir/t/' . $productId;
        }

        return $url;
    }

    private function request(string $method, array $fields, bool $multipart = false): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Telegram is not configured'];
        }

        $ch = curl_init(self::API_BASE . $this->token . '/' . $method);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
        ];

        if ($multipart) {
            $options[CURLOPT_POSTFIELDS] = $fields;
        } else {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $fields,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return ['ok' => false, 'error' => $error ?: 'transport_error', 'status' => $status];
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'invalid_response', 'status' => $status];
        }

        if (empty($decoded['ok']) && !isset($decoded['error'])) {
            $decoded['error'] = (string)($decoded['description'] ?? 'Telegram API error');
        }

        return $decoded;
    }
}
