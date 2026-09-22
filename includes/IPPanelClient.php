<?php

/**
 * Minimal IPPanel Edge API client.
 *
 * Credentials are read only from environment variables and are never stored in git.
 * If IPPanel Edge is unreachable from the management server (502/503/504 or
 * transport failure), requests can fail over through the BAJI WordPress host.
 * The relay is authenticated with a timestamped one-time HMAC derived from the
 * existing IPPanel API key, so no additional shared secret is required.
 */
class IPPanelClient
{
    private string $apiKey;
    private string $sender;
    private string $baseUrl;
    private string $proxyBaseUrl;

    public function __construct()
    {
        $this->apiKey = trim((string)getenv('IPPANEL_API_KEY'));
        $this->sender = trim((string)getenv('IPPANEL_SENDER'));
        $this->baseUrl = rtrim(trim((string)(getenv('IPPANEL_BASE_URL') ?: 'https://edge.ippanel.com/v1')), '/');
        $this->proxyBaseUrl = rtrim(trim((string)(getenv('IPPANEL_PROXY_URL') ?: 'https://bajistyle.ir/wp-json/baji/v1/sms-proxy')), '/');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->sender !== '';
    }

    public function status(): array
    {
        return [
            'provider' => 'ippanel',
            'configured' => $this->isConfigured(),
            'sender_configured' => $this->sender !== '',
            'api_key_configured' => $this->apiKey !== '',
            'relay_configured' => $this->proxyBaseUrl !== '',
        ];
    }

    public function send(string $recipient, string $message): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('IPPanel is not configured. Set IPPANEL_API_KEY and IPPANEL_SENDER on the server.');
        }

        $recipient = $this->normalizeIranMobile($recipient);
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('SMS message cannot be empty.');
        }
        if (mb_strlen($message) > 1000) {
            throw new InvalidArgumentException('SMS message is too long.');
        }

        try {
            return $this->request('POST', '/api/send', [
                'sending_type' => 'webservice',
                'from_number' => $this->sender,
                'message' => $message,
                'params' => [
                    'recipients' => [$recipient],
                ],
            ]);
        } catch (RuntimeException $e) {
            if (!$this->shouldUseRelay($e)) {
                throw $e;
            }

            return $this->relayRequest('send', [
                'recipient' => $recipient,
                'message' => $message,
            ]);
        }
    }

    public function checkRelay(): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('IPPanel is not configured.');
        }

        return $this->relayRequest('status', []);
    }

    public function getMessageStatus(int $messageId): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('IPPanel is not configured.');
        }
        if ($messageId <= 0) {
            throw new InvalidArgumentException('message_id must be a positive integer.');
        }

        $result = $this->relayRequest('message-status', ['message_id' => $messageId]);
        return is_array($result['report'] ?? null) ? $result['report'] : $result;
    }

    private function shouldUseRelay(RuntimeException $e): bool
    {
        $message = $e->getMessage();

        return str_starts_with($message, 'IPPanel request failed:')
            || (bool)preg_match('/\(HTTP (502|503|504)\)$/', $message);
    }

    private function normalizeIranMobile(string $mobile): string
    {
        $mobile = preg_replace('/\D+/', '', trim($mobile));

        if (str_starts_with($mobile, '0098')) {
            $mobile = substr($mobile, 2);
        } elseif (strlen($mobile) === 11 && str_starts_with($mobile, '09')) {
            $mobile = '98' . substr($mobile, 1);
        } elseif (strlen($mobile) === 10 && str_starts_with($mobile, '9')) {
            $mobile = '98' . $mobile;
        }

        if (!preg_match('/^989\d{9}$/', $mobile)) {
            throw new InvalidArgumentException('Recipient must be a valid Iranian mobile number.');
        }

        return '+' . $mobile;
    }

    private function request(string $method, string $path, array $payload): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('IPPanel request failed: ' . $error);
        }
        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $safeMessage = is_array($decoded) ? (string)($decoded['message'] ?? $decoded['error'] ?? 'IPPanel API error') : 'IPPanel API error';
            throw new RuntimeException($safeMessage . ' (HTTP ' . $status . ')');
        }
        return [
            'success' => true,
            'provider' => 'ippanel',
            'route' => 'direct',
            'status' => $status,
            'response' => is_array($decoded) ? $decoded : ['raw' => (string)$body],
        ];
    }

    private function relayRequest(string $action, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Could not encode SMS relay request.');
        }

        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $this->apiKey);

        $ch = curl_init($this->proxyBaseUrl . '/' . rawurlencode($action));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Baji-Timestamp: ' . $timestamp,
                'X-Baji-Nonce: ' . $nonce,
                'X-Baji-Signature: ' . $signature,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $responseBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $error !== '') {
            throw new RuntimeException('SMS relay request failed: ' . $error);
        }

        $decoded = json_decode((string)$responseBody, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $safeMessage = is_array($decoded)
                ? (string)($decoded['message'] ?? $decoded['code'] ?? 'SMS relay error')
                : 'SMS relay error';
            $providerHttp = is_array($decoded) && isset($decoded['data']['provider_http'])
                ? ' / provider HTTP ' . (int)$decoded['data']['provider_http']
                : '';
            throw new RuntimeException($safeMessage . ' (relay HTTP ' . $status . $providerHttp . ')');
        }

        return [
            'success' => (bool)($decoded['success'] ?? true),
            'accepted' => (bool)($decoded['accepted'] ?? ($decoded['success'] ?? true)),
            'provider' => 'ippanel',
            'route' => (string)($decoded['route'] ?? 'wordpress-relay'),
            'status' => (int)($decoded['provider_http'] ?? $status),
            'message_id' => isset($decoded['message_id']) ? (int)$decoded['message_id'] : null,
            'final_status' => (string)($decoded['final_status'] ?? ''),
            'confirmed_sent' => (bool)($decoded['confirmed_sent'] ?? false),
            'delivery_confirmed' => (bool)($decoded['delivery_confirmed'] ?? false),
            'report' => is_array($decoded['report'] ?? null) ? $decoded['report'] : null,
            'response' => $decoded['response'] ?? [],
        ];
    }
}
