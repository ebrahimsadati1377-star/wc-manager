<?php

class BasalamChatSmsNotifier
{
    private PDO $db;
    private IPPanelClient $sms;

    public function __construct()
    {
        $this->db = Database::get();
        $this->sms = new IPPanelClient();
        $this->ensureTable();
    }

    public function handle(array $payload): array
    {
        if ((string)getSetting('basalam_chat_sms_enabled', '1') !== '1') {
            return ['success' => true, 'status' => 'disabled'];
        }

        $event = $this->extractEvent($payload);
        $messageId = (int)($event['id'] ?? 0);
        $chatId = (int)($event['chat_id'] ?? 0);
        $senderId = (int)($event['sender_id'] ?? 0);

        if ($messageId <= 0 || $chatId <= 0) {
            throw new InvalidArgumentException('Basalam webhook payload is missing message/chat id.');
        }
        $type = trim((string)($event['message_type'] ?? ''));
        $text = trim((string)($event['message']['text'] ?? ''));

        if (!$this->claimMessage($messageId, $chatId, $senderId, $type)) {
            return [
                'success' => true,
                'status' => 'duplicate',
                'message_id' => $messageId,
                'chat_id' => $chatId,
            ];
        }

        $mobile = trim((string)getSetting('basalam_chat_sms_mobile', ''));
        if ($mobile === '') {
            $this->markFailed($messageId, 'SMS destination is not configured.');
            throw new RuntimeException('SMS destination is not configured.');
        }

        try {
            $smsText = $this->buildSmsText($text, $type, $chatId);
            $result = $this->sms->send($mobile, $smsText);
            $this->markSent($messageId, $result);

            return [
                'success' => true,
                'status' => 'sent',
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'sms_route' => (string)($result['route'] ?? ''),
            ];
        } catch (Throwable $e) {
            $this->markFailed($messageId, $e->getMessage());
            throw $e;
        }
    }

    private function extractEvent(array $payload): array
    {
        $candidates = [$payload];

        foreach (['data', 'payload', 'event'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $candidates[] = $payload[$key];
            }
        }

        if (isset($payload['event']['data']) && is_array($payload['event']['data'])) {
            $candidates[] = $payload['event']['data'];
        }

        foreach ($candidates as $candidate) {
            if (isset($candidate['id'], $candidate['chat_id'])) {
                return $candidate;
            }
        }

        return $payload;
    }

    private function claimMessage(int $messageId, int $chatId, int $senderId, string $type): bool
    {
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO basalam_chat_sms_notifications
             (message_id, chat_id, sender_id, message_type, status, attempts, received_at, last_received_at)
             VALUES (:mid, :cid, :sid, :type, "processing", 1, NOW(), NOW())'
        );
        $insert->execute([
            'mid' => $messageId,
            'cid' => $chatId,
            'sid' => $senderId,
            'type' => mb_substr($type, 0, 40),
        ]);

        if ($insert->rowCount() === 1) {
            return true;
        }

        $select = $this->db->prepare(
            'SELECT status FROM basalam_chat_sms_notifications WHERE message_id = :mid LIMIT 1'
        );
        $select->execute(['mid' => $messageId]);
        $status = (string)($select->fetchColumn() ?: '');

        if (in_array($status, ['sent', 'processing'], true)) {
            return false;
        }

        $retry = $this->db->prepare(
            'UPDATE basalam_chat_sms_notifications
             SET status = "processing", attempts = attempts + 1, last_received_at = NOW(), last_error = NULL
             WHERE message_id = :mid'
        );
        $retry->execute(['mid' => $messageId]);
        return true;
    }

    private function buildSmsText(string $text, string $type, int $chatId): string
    {
        $preview = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        if ($preview !== '') {
            $preview = mb_substr($preview, 0, 110);
            return "باجی: پیام جدید در باسلام داری.\nپیام: {$preview}\nبرای پاسخ وارد باسلام شو.";
        }

        $kind = $type !== '' ? $type : 'غیرمتنی';
        return "باجی: یک پیام جدید ({$kind}) در باسلام داری.\nبرای پاسخ وارد باسلام شو.\nگفتگو: {$chatId}";
    }

    private function markSent(int $messageId, array $result): void
    {
        $stmt = $this->db->prepare(
            'UPDATE basalam_chat_sms_notifications
             SET status = "sent", sms_sent_at = NOW(), last_error = NULL,
                 sms_route = :route, sms_http_status = :http
             WHERE message_id = :mid'
        );
        $stmt->execute([
            'mid' => $messageId,
            'route' => mb_substr((string)($result['route'] ?? ''), 0, 40),
            'http' => (int)($result['status'] ?? 0),
        ]);
    }

    private function markFailed(int $messageId, string $error): void
    {
        $stmt = $this->db->prepare(
            'UPDATE basalam_chat_sms_notifications
             SET status = "failed", last_error = :error, last_received_at = NOW()
             WHERE message_id = :mid'
        );
        $stmt->execute([
            'mid' => $messageId,
            'error' => mb_substr($error, 0, 500),
        ]);
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS basalam_chat_sms_notifications (
                message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                chat_id BIGINT UNSIGNED NOT NULL,
                sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                message_type VARCHAR(40) NOT NULL DEFAULT "",
                status VARCHAR(20) NOT NULL DEFAULT "processing",
                attempts INT UNSIGNED NOT NULL DEFAULT 1,
                last_error VARCHAR(500) NULL,
                sms_route VARCHAR(40) NULL,
                sms_http_status INT NULL,
                received_at DATETIME NOT NULL,
                last_received_at DATETIME NOT NULL,
                sms_sent_at DATETIME NULL,
                KEY idx_status (status),
                KEY idx_chat_id (chat_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
