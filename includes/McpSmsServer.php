<?php

/**
 * SMS extension for the WC Manager MCP server.
 * Keeps the core MCP implementation untouched while exposing IPPanel tools.
 */
class WcManagerSmsMcpServer extends WcManagerMcpServer
{
    private IPPanelClient $sms;

    public function __construct(?IPPanelClient $sms = null)
    {
        parent::__construct();
        $this->sms = $sms ?? new IPPanelClient();
    }

    public function tools(): array
    {
        $tools = parent::tools();
        $tools[] = $this->smsTool(
            'get_sms_status',
            'Check BAJI SMS status',
            'Checks whether the server-side IPPanel connection is configured. Never returns the API key.',
            ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false],
            true,
            false,
            true
        );
        $tools[] = $this->smsTool(
            'send_sms',
            'Send an SMS with BAJI IPPanel',
            'Sends one explicitly provided SMS to one Iranian mobile number through the configured BAJI IPPanel account.',
            [
                'type' => 'object',
                'required' => ['recipient', 'message'],
                'properties' => [
                    'recipient' => [
                        'type' => 'string',
                        'minLength' => 10,
                        'maxLength' => 16,
                        'description' => 'Iranian mobile number, e.g. 09xxxxxxxxx or +989xxxxxxxxx.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 1000,
                    ],
                ],
                'additionalProperties' => false,
            ],
            false,
            false,
            false
        );
        return $tools;
    }

    public function callTool(string $name, array $arguments): array
    {
        if ($name === 'get_sms_status') {
            return $this->smsResult($this->sms->status());
        }

        if ($name === 'send_sms') {
            try {
                $recipient = trim((string)($arguments['recipient'] ?? ''));
                $message = trim((string)($arguments['message'] ?? ''));
                if ($recipient === '' || $message === '') {
                    return $this->smsError('recipient and message are required.');
                }
                $result = $this->sms->send($recipient, $message);
                return $this->smsResult($result);
            } catch (Throwable $e) {
                return $this->smsError($e->getMessage());
            }
        }

        return parent::callTool($name, $arguments);
    }

    private function smsTool(
        string $name,
        string $title,
        string $description,
        array $inputSchema,
        bool $readOnly,
        bool $destructive,
        bool $idempotent
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            '_meta' => (object)[],
            'annotations' => [
                'title' => $title,
                'readOnlyHint' => $readOnly,
                'destructiveHint' => $destructive,
                'idempotentHint' => $idempotent,
                'openWorldHint' => true,
            ],
        ];
    }

    private function smsResult(array $payload): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $payload,
            'isError' => false,
        ];
    }

    private function smsError(string $message): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => $message,
            ]],
            'structuredContent' => ['error' => $message],
            'isError' => true,
        ];
    }
}
