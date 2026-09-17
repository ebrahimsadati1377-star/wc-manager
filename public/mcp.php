<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ChatGPTApi.php';
require_once __DIR__ . '/../includes/ChatImageService.php';
require_once __DIR__ . '/../includes/OAuthService.php';
require_once __DIR__ . '/../includes/McpServer.php';
require_once __DIR__ . '/../includes/McpSmsServer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    mcpHttpJson(405, ['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'MCP endpoint accepts POST requests only.']]);
}

$raw = file_get_contents('php://input');
$decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
    mcpHttpJson(400, ['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error']]);
}

mcpValidateModernHeaders($decoded);
$requestMethod = trim((string)($decoded['method'] ?? ''));
$requestId = $decoded['id'] ?? null;
$diag = [
    'ts' => gmdate('c'),
    'method' => $requestMethod,
    'id_type' => gettype($requestId),
    'protocol_header' => (string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''),
    'accept' => (string)($_SERVER['HTTP_ACCEPT'] ?? ''),
    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'param_keys' => is_array($decoded['params'] ?? null) ? array_keys($decoded['params']) : [],
    'requested_protocol' => (string)($decoded['params']['protocolVersion'] ?? ''),
    'client_name' => (string)($decoded['params']['clientInfo']['name'] ?? ''),
];
@file_put_contents('/tmp/wc-mcp-discovery.log', json_encode($diag, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
$server = new WcManagerSmsMcpServer();

if ($requestMethod === 'tools/call') {
    $toolName = trim((string)($decoded['params']['name'] ?? ''));
    if ($toolName === 'send_sms') $requiredScopes = ['store.write'];
    elseif ($toolName === 'get_sms_status') $requiredScopes = ['store.read'];
    else $requiredScopes = WcManagerOAuthService::toolScopes($toolName);
    $auth = mcpAuthenticate($requiredScopes);
    if (!$auth['ok']) mcpHttpJson(200, mcpAuthRequiredResponse($requestId, $requiredScopes, $auth['error'], $auth['description']));
}

$response = $server->dispatch($decoded);
$responseResult = is_array($response['result'] ?? null) ? $response['result'] : [];
$responseProtocol = $responseResult['protocolVersion'] ?? ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '2025-03-26');
if (in_array($responseProtocol, WcManagerMcpServer::SUPPORTED_PROTOCOLS, true)) header('MCP-Protocol-Version: ' . $responseProtocol);
if ($response === null) { http_response_code(202); exit; }
if (in_array($requestMethod, ['tools/list', 'server/discover'], true)
    && isset($response['result']['tools']) && is_array($response['result']['tools'])) {
    $response['result']['tools'] = array_map('mcpApplySubmissionPolicy', $response['result']['tools']);
}
apiLogActivity('mcp_request', $requestMethod, isset($decoded['params']['name']) ? 'tool=' . (string)$decoded['params']['name'] : '');
mcpHttpJson(200, $response);

function mcpAuthenticate(array $requiredScopes): array
{
    $header = chatgptApiAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) return ['ok'=>false,'error'=>'invalid_token','description'=>'Connect WC Manager to use this tool.'];
    $plain = trim((string)$match[1]);
    if ($plain === '') return ['ok'=>false,'error'=>'invalid_token','description'=>'Bearer token is empty.'];
    try {
        $oauth = new WcManagerOAuthService();
        $token = $oauth->authenticateAccessToken($plain);
        if (is_array($token)) {
            if (!$oauth->hasScopes($token, $requiredScopes)) return ['ok'=>false,'error'=>'insufficient_scope','description'=>'Reconnect WC Manager with the permissions required for this action.'];
            $GLOBALS['wc_manager_oauth_context'] = $token;
            $GLOBALS['chatgpt_api_current_token_id'] = 'oauth_' . (string)($token['id'] ?? 'token');
            return ['ok'=>true,'mode'=>'oauth'];
        }
    } catch (Throwable $e) { error_log('[wc-manager] MCP OAuth validation failed: ' . $e->getMessage()); }
    $candidateHash = hash('sha256', $plain);
    foreach (chatgptApiTokenRecords() as $record) {
        if (hash_equals((string)$record['hash'], $candidateHash)) {
            $GLOBALS['chatgpt_api_current_token_id'] = (string)($record['id'] ?? 'legacy');
            $GLOBALS['wc_manager_oauth_context'] = ['legacy'=>true,'scope_list'=>WcManagerOAuthService::supportedScopes()];
            return ['ok'=>true,'mode'=>'legacy'];
        }
    }
    return ['ok'=>false,'error'=>'invalid_token','description'=>'The WC Manager access token is invalid or expired.'];
}

function mcpAuthRequiredResponse($id, array $scopes, string $error, string $description): array
{
    $scopeString = implode(' ', $scopes);
    $challenge = 'Bearer resource_metadata="' . WcManagerOAuthService::ISSUER . '/.well-known/oauth-protected-resource"';
    if ($scopeString !== '') $challenge .= ', scope="' . addcslashes($scopeString, "\\\"") . '"';
    if ($error !== '') $challenge .= ', error="' . addcslashes($error, "\\\"") . '"';
    if ($description !== '') $challenge .= ', error_description="' . addcslashes($description, "\\\"") . '"';
    header('WWW-Authenticate: ' . $challenge);
    return ['jsonrpc'=>'2.0','id'=>$id,'result'=>['content'=>[['type'=>'text','text'=>'WC Manager authorization is required for this action.']], '_meta'=>['mcp/www_authenticate'=>[$challenge]],'isError'=>true]];
}

function mcpApplySubmissionPolicy(array $tool): array
{
    $name = trim((string)($tool['name'] ?? ''));
    if ($name === 'send_sms') $scopes = ['store.write'];
    elseif ($name === 'get_sms_status') $scopes = ['store.read'];
    else $scopes = WcManagerOAuthService::toolScopes($name);
    $tool['securitySchemes'] = [['type'=>'oauth2','scopes'=>$scopes]];
    if (isset($tool['inputSchema']) && is_array($tool['inputSchema'])) {
        $tool['inputSchema'] = mcpSanitizeSchemaForChatGPT($tool['inputSchema']);
    }
    unset($tool['outputSchema']);
    if (!isset($tool['annotations']) || !is_array($tool['annotations'])) $tool['annotations'] = [];
    $destructive = in_array($name, ['update_product','update_article','update_basalam_product','sync_basalam_product','send_sms'], true);
    $tool['annotations']['destructiveHint'] = $destructive;
    $tool['annotations']['openWorldHint'] = true;
    return $tool;
}

function mcpSanitizeSchemaForChatGPT(array $schema): array
{
    $allowed = ['type'=>true,'properties'=>true,'required'=>true,'description'=>true,'items'=>true];
    $out = [];
    foreach ($schema as $key => $value) {
        if (!isset($allowed[$key])) continue;
        if ($key === 'required') { if (is_array($value)) $out[$key] = array_values(array_filter($value, 'is_string')); continue; }
        if ($key === 'properties' && is_array($value)) {
            $props = [];
            foreach ($value as $name => $propertySchema) if (is_array($propertySchema)) $props[$name] = mcpSanitizeSchemaForChatGPT($propertySchema);
            $out[$key] = $props ?: (object)[]; continue;
        }
        if ($key === 'items' && is_array($value)) { $out[$key] = mcpSanitizeSchemaForChatGPT($value); continue; }
        if ($key === 'type' && is_string($value) && in_array($value, ['object','string','integer','number','boolean','array'], true)) { $out[$key] = $value; continue; }
        if ($key === 'description' && is_string($value)) $out[$key] = $value;
    }
    if (!isset($out['type']) && isset($schema['properties'])) $out['type'] = 'object';
    return $out;
}

function mcpValidateModernHeaders(array $request): void
{
    $protocol = trim((string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
    // Be forward-compatible during MCP discovery. Clients may probe with a newer
    // protocol header before initialize negotiates the version. Rejecting that
    // probe at HTTP level makes ChatGPT report "action discovery failed".
    // initialize() below performs protocol negotiation and returns a supported version.
    $bodyMethod = trim((string)($request['method'] ?? ''));
    $headerMethod = trim((string)($_SERVER['HTTP_MCP_METHOD'] ?? ''));
    if ($headerMethod !== '' && $bodyMethod !== '' && $headerMethod !== $bodyMethod) mcpHttpJson(400, ['jsonrpc'=>'2.0','id'=>$request['id'] ?? null,'error'=>['code'=>-32020,'message'=>'Mcp-Method header does not match JSON-RPC method.']]);
    $bodyName = trim((string)($request['params']['name'] ?? ''));
    $headerName = trim((string)($_SERVER['HTTP_MCP_NAME'] ?? ''));
    if ($bodyName !== '' && $headerName !== '' && $bodyName !== $headerName) mcpHttpJson(400, ['jsonrpc'=>'2.0','id'=>$request['id'] ?? null,'error'=>['code'=>-32020,'message'=>'Mcp-Name header does not match request params.name.']]);
}

function mcpHttpJson(int $status, array $payload): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json !== false ? $json : '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"JSON encoding failed"}}';
    exit;
}
