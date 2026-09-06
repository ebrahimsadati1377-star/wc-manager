<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ChatGPTApi.php';
require_once __DIR__ . '/../includes/ChatImageService.php';
require_once __DIR__ . '/../includes/McpServer.php';

header('Content-Type: application/json; charset=utf-8');
header('MCP-Protocol-Version: ' . WcManagerMcpServer::LATEST_PROTOCOL);
header('X-Content-Type-Options: nosniff');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    mcpHttpJson(405, [
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32600, 'message' => 'MCP endpoint accepts POST requests only.'],
    ]);
}

mcpRequireBearerAuth();

$raw = file_get_contents('php://input');
$decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
    mcpHttpJson(400, [
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32700, 'message' => 'Parse error'],
    ]);
}

mcpValidateModernHeaders($decoded);

$server = new WcManagerMcpServer();
$response = $server->dispatch($decoded);
if ($response === null) {
    http_response_code(202);
    exit;
}

apiLogActivity(
    'mcp_request',
    trim((string)($decoded['method'] ?? '')),
    isset($decoded['params']['name']) ? 'tool=' . (string)$decoded['params']['name'] : ''
);

mcpHttpJson(200, $response);

function mcpRequireBearerAuth(): void
{
    $records = chatgptApiTokenRecords();
    if (!$records) {
        mcpHttpJson(503, [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32001,
                'message' => 'MCP authentication is not configured.',
            ],
        ]);
    }

    $header = chatgptApiAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        header('WWW-Authenticate: Bearer realm="WC Manager MCP"');
        mcpHttpJson(401, [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32001,
                'message' => 'Bearer authentication is required.',
            ],
        ]);
    }

    $token = trim((string)$match[1]);
    $candidateHash = $token !== '' ? hash('sha256', $token) : '';
    foreach ($records as $record) {
        if ($candidateHash !== '' && hash_equals((string)$record['hash'], $candidateHash)) {
            $GLOBALS['chatgpt_api_current_token_id'] = (string)($record['id'] ?? 'mcp');
            return;
        }
    }

    header('WWW-Authenticate: Bearer realm="WC Manager MCP"');
    mcpHttpJson(401, [
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => [
            'code' => -32001,
            'message' => 'Invalid bearer token.',
        ],
    ]);
}

function mcpValidateModernHeaders(array $request): void
{
    $protocol = trim((string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
    if ($protocol === '') {
        return;
    }

    if ($protocol !== WcManagerMcpServer::LATEST_PROTOCOL) {
        mcpHttpJson(400, [
            'jsonrpc' => '2.0',
            'id' => $request['id'] ?? null,
            'error' => [
                'code' => -32020,
                'message' => 'Unsupported MCP protocol version.',
                'data' => ['supported' => [WcManagerMcpServer::LATEST_PROTOCOL, WcManagerMcpServer::LEGACY_PROTOCOL]],
            ],
        ]);
    }

    $bodyMethod = trim((string)($request['method'] ?? ''));
    $headerMethod = trim((string)($_SERVER['HTTP_MCP_METHOD'] ?? ''));
    if ($headerMethod !== '' && $bodyMethod !== '' && $headerMethod !== $bodyMethod) {
        mcpHttpJson(400, [
            'jsonrpc' => '2.0',
            'id' => $request['id'] ?? null,
            'error' => ['code' => -32020, 'message' => 'Mcp-Method header does not match JSON-RPC method.'],
        ]);
    }

    $bodyName = trim((string)($request['params']['name'] ?? ''));
    $headerName = trim((string)($_SERVER['HTTP_MCP_NAME'] ?? ''));
    if ($bodyName !== '' && $headerName !== '' && $bodyName !== $headerName) {
        mcpHttpJson(400, [
            'jsonrpc' => '2.0',
            'id' => $request['id'] ?? null,
            'error' => ['code' => -32020, 'message' => 'Mcp-Name header does not match request params.name.'],
        ]);
    }
}

function mcpHttpJson(int $status, array $payload): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json !== false ? $json : '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"JSON encoding failed"}}';
    exit;
}
