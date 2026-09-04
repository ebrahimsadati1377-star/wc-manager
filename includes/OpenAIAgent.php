<?php

function wcAgentOpenAiKeyFromSession(): string
{
    $env = getenv('OPENAI_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return trim((string)($_SESSION['wc_agent_openai_key'] ?? ''));
}

function wcAgentModel(): string
{
    $model = trim((string)($_SESSION['wc_agent_model'] ?? 'gpt-5.6-terra'));
    if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $model)) {
        return 'gpt-5.6-terra';
    }
    return $model;
}

function wcAgentTools(): array
{
    return [
        [
            'type' => 'function',
            'name' => 'check_connection',
            'description' => 'Check WC Manager and WooCommerce connection status.',
            'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        ],
        [
            'type' => 'function',
            'name' => 'list_products',
            'description' => 'List or search WooCommerce products. Use meta.total for the total product count.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'search' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'sku' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'stock_status' => ['type' => 'string'],
                    'orderby' => ['type' => 'string'],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_product',
            'description' => 'Get one WooCommerce product by numeric ID.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
        ],
        [
            'type' => 'function',
            'name' => 'list_categories',
            'description' => 'List or search WooCommerce product categories.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string'],
                    'parent' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'type' => 'function',
            'name' => 'list_attributes',
            'description' => 'List WooCommerce global attributes, or terms for one attribute ID.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['attribute_id' => ['type' => 'integer', 'minimum' => 1]],
                'additionalProperties' => false,
            ],
        ],
        [
            'type' => 'function',
            'name' => 'list_basalam_products',
            'description' => 'List or search products in the configured Basalam vendor.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'title' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'statuses' => ['type' => 'string'],
                    'skus' => ['type' => 'string'],
                    'stock_gte' => ['type' => 'integer'],
                    'stock_lte' => ['type' => 'integer'],
                    'sort' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_basalam_product',
            'description' => 'Get one Basalam product by numeric ID.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
        ],
    ];
}

function wcAgentHttpJson(string $url, string $method, array $headers, ?array $body = null, int $timeout = 40): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'WC-Manager-Agent/1.0',
    ];
    if ($body !== null) {
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode JSON request.');
        }
        $opts[CURLOPT_POSTFIELDS] = $encoded;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('HTTP request failed: ' . $err);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Remote service returned invalid JSON (HTTP ' . $status . ').');
    }

    if ($status < 200 || $status >= 300) {
        $message = (string)($decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $status));
        throw new RuntimeException($message);
    }

    return $decoded;
}

function wcAgentCallWcApi(string $token, string $endpoint, array $query = []): array
{
    $base = rtrim(currentUrl(), '/') . '/api/' . ltrim($endpoint, '/');
    if ($query) {
        $query = array_filter($query, static fn($value) => $value !== null && $value !== '');
        $base .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return wcAgentHttpJson($base, 'GET', [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ], null, 35);
}

function wcAgentDispatchTool(string $name, array $args, string $wcToken): array
{
    switch ($name) {
        case 'check_connection':
            return wcAgentCallWcApi($wcToken, 'health.php');
        case 'list_products':
            if (isset($args['per_page'])) {
                $args['per_page'] = max(1, min(50, (int)$args['per_page']));
            } else {
                $args['per_page'] = 20;
            }
            return wcAgentCallWcApi($wcToken, 'products.php', $args);
        case 'get_product':
            return wcAgentCallWcApi($wcToken, 'product.php', ['id' => (int)($args['id'] ?? 0)]);
        case 'list_categories':
            return wcAgentCallWcApi($wcToken, 'categories.php', $args);
        case 'list_attributes':
            return wcAgentCallWcApi($wcToken, 'attributes.php', $args);
        case 'list_basalam_products':
            if (isset($args['per_page'])) {
                $args['per_page'] = max(1, min(50, (int)$args['per_page']));
            } else {
                $args['per_page'] = 20;
            }
            return wcAgentCallWcApi($wcToken, 'basalam-products.php', $args);
        case 'get_basalam_product':
            return wcAgentCallWcApi($wcToken, 'basalam-product.php', ['id' => (int)($args['id'] ?? 0)]);
        default:
            throw new RuntimeException('Unknown tool: ' . $name);
    }
}

function wcAgentOpenAiRequest(string $apiKey, array $payload): array
{
    return wcAgentHttpJson('https://api.openai.com/v1/responses', 'POST', [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ], $payload, 90);
}

function wcAgentOutputText(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text']) && trim($response['output_text']) !== '') {
        return trim($response['output_text']);
    }
    $parts = [];
    foreach (($response['output'] ?? []) as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
            continue;
        }
        foreach (($item['content'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = (string)$content['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
}

function wcAgentRun(string $message, array $history, string $wcToken, string $apiKey): string
{
    $input = [];
    $history = array_slice($history, -12);
    foreach ($history as $row) {
        if (!is_array($row)) {
            continue;
        }
        $role = ($row['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string)($row['text'] ?? ''));
        if ($text !== '') {
            $input[] = ['role' => $role, 'content' => $text];
        }
    }
    $input[] = ['role' => 'user', 'content' => $message];

    $instructions = 'You are BajiStyle WC Manager Agent. Reply in Persian unless the user asks otherwise. '
        . 'Use the provided tools for all live store facts; never invent product, stock, price, category, or Basalam data. '
        . 'This pilot is READ-ONLY. You cannot create, update, delete, publish, sync, or otherwise mutate anything. '
        . 'If asked to change data, clearly say that write access is disabled in this pilot. Keep answers concise and useful.';

    $tools = wcAgentTools();
    for ($round = 0; $round < 8; $round++) {
        $response = wcAgentOpenAiRequest($apiKey, [
            'model' => wcAgentModel(),
            'instructions' => $instructions,
            'input' => $input,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'store' => false,
        ]);

        $calls = [];
        foreach (($response['output'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                $calls[] = $item;
            }
        }

        if (!$calls) {
            $text = wcAgentOutputText($response);
            if ($text === '') {
                throw new RuntimeException('OpenAI returned no text response.');
            }
            return $text;
        }

        foreach (($response['output'] ?? []) as $item) {
            if (is_array($item)) {
                $input[] = $item;
            }
        }

        foreach ($calls as $call) {
            $name = (string)($call['name'] ?? '');
            $callId = (string)($call['call_id'] ?? '');
            $args = json_decode((string)($call['arguments'] ?? '{}'), true);
            if (!is_array($args)) {
                $args = [];
            }
            try {
                $toolResult = wcAgentDispatchTool($name, $args, $wcToken);
                $output = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $e) {
                $output = json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($output === false) {
                $output = '{"success":false,"error":"Could not encode tool output"}';
            }
            if (strlen($output) > 180000) {
                $output = substr($output, 0, 180000) . '\n...[truncated]';
            }
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $callId,
                'output' => $output,
            ];
        }
    }

    throw new RuntimeException('Agent exceeded the maximum tool-call rounds.');
}
