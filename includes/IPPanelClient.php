<?php

/**
 * Minimal IPPanel Edge API client.
 * Credentials are read only from environment variables and are never stored in git.
 */
class IPPanelClient
{
    private string $apiKey;
    private string $sender;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = trim((string)getenv('IPPANEL_API_KEY'));
        $this->sender = trim((string)getenv('IPPANEL_SENDER'));
        $this->baseUrl = rtrim(trim((string)(getenv('IPPANEL_BASE_URL') ?: 'https://edge.ippanel.com/v1')), '/');
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

        return $this->request('POST', '/api/send', [
            'sending_type' => 'webservice',
            'from_number' => $this->sender,
            'message' => $message,
            'params' => [
                'recipients' => [$recipient],
            ],
        ]);
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
            'status' => $status,
            'response' => is_array($decoded) ? $decoded : ['raw' => (string)$body],
        ];
    }
}
