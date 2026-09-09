<?php
// Offline contract tests: no credentials, network calls, or production writes.
class WooCommerceClient {
    public array $images = [['id' => 9]];
    public function uploadMedia($path, $name): array {
        return ['body' => ['id' => 42, 'source_url' => 'https://example.org/image.jpg'], 'status' => 201];
    }
    public function getProduct($id): array { return ['body' => ['id' => $id, 'images' => $this->images]]; }
    public function updateProduct($id, $changes): array {
        $this->images = $changes['images'];
        return ['body' => ['id' => $id, 'images' => $this->images]];
    }
}
class BasalamClient {}
class ChatImageService {
    public array $received = [];
    public function import(array $input): array {
        $this->received = $input;
        return ['url' => 'https://example.org/image.jpg', 'local_path' => '/test/image.jpg',
            'filename' => 'image.jpg', 'content_type' => 'image/jpeg', 'size' => 100];
    }
}
function apiLogActivity(...$args): void {}
require __DIR__ . '/../includes/McpServer.php';
function check($condition, $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$wc = new WooCommerceClient();
$images = new ChatImageService();
$server = new WcManagerMcpServer($wc, new BasalamClient(), $images);
// Validate the serialized discovery contract, including zero-argument tools.
$wire = json_decode(json_encode($server->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])));
foreach ($wire->result->tools as $descriptor) {
    check(is_object($descriptor->inputSchema->properties), $descriptor->name . ': properties must serialize as an object');
}
foreach ($server->tools() as $tool) {
    if (!in_array($tool['name'], ['upload_image', 'upload_and_attach_product_image'], true)) { continue; }
    check($tool['_meta']['openai/fileParams'] === ['file'], 'File metadata missing');
    $schema = $tool['inputSchema']['properties']['file'];
    check($schema['required'] === ['download_url', 'file_id'], 'Incorrect required file properties');
    foreach (['download_url', 'file_id', 'file_name', 'mime_type'] as $key) {
        check($schema['properties'][$key]['type'] === 'string', 'Missing file property');
    }
}
$file = ['download_url' => 'https://example.org/signed.jpg', 'file_id' => 'file_test'];
$result = $server->callTool('upload_image', ['file' => $file, 'copy_to_wordpress' => true]);
check(!$result['isError'], 'MCP file upload failed');
check($images->received['openaiFileIdRefs'][0]['download_link'] === $file['download_url'], 'URL not passed to importer');
check($result['structuredContent']['wordpress_media']['id'] === 42, 'Media ID not returned');
check(!isset($result['structuredContent']['wc_manager_media']['local_path']), 'Local path leaked');
$result = $server->callTool('upload_and_attach_product_image', ['product_id' => 7, 'file' => $file, 'position' => 'featured']);
check(!$result['isError'] && $wc->images === [['id' => 42], ['id' => 9]], 'Existing gallery not preserved');
foreach ([null, [], ['download_url' => 'https://example.org/x']] as $invalid) {
    check($server->callTool('upload_image', ['file' => $invalid])['isError'], 'Invalid file accepted');
}
check($server->callTool('upload_image', ['file' => $file, 'url' => 'https://example.org/other'])['isError'], 'Ambiguous source accepted');
foreach ([
    ['url' => 'https://example.org/x'],
    ['base64' => 'test'],
    ['openaiFileIdRefs' => [['download_link' => 'https://example.org/x']]]
] as $legacy) {
    check(!$server->callTool('upload_image', $legacy)['isError'], 'Legacy input rejected');
    check($images->received === $legacy, 'Legacy input changed');
}
echo "MCP file contract and upload/attach regression tests passed.\n";
