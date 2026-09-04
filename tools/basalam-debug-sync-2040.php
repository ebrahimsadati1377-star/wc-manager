<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

final class DebugBasalamClient2040 extends BasalamClient
{
    public function uploadRemoteImage(string $url): array
    {
        $result = parent::uploadRemoteImage($url);
        if (!empty($result['error'])) {
            $this->emit('IMAGE_ERROR', [
                'status' => (int)($result['status'] ?? 0),
                'error' => (string)($result['error'] ?? ''),
                'body' => $result['body'] ?? [],
            ]);
        }
        return $result;
    }

    public function createProduct(array $data): array
    {
        $this->emit('CREATE_PAYLOAD', [
            'keys' => array_keys($data),
            'name' => (string)($data['name'] ?? ''),
            'category_id' => $data['category_id'] ?? null,
            'status' => $data['status'] ?? null,
            'preparation_days' => $data['preparation_days'] ?? null,
            'primary_price' => $data['primary_price'] ?? null,
            'stock' => $data['stock'] ?? null,
            'weight' => $data['weight'] ?? null,
            'package_weight' => $data['package_weight'] ?? null,
            'photo' => $data['photo'] ?? null,
            'photos_count' => isset($data['photos']) && is_array($data['photos']) ? count($data['photos']) : 0,
            'virtual' => $data['virtual'] ?? null,
            'is_wholesale' => $data['is_wholesale'] ?? null,
            'sku' => $data['sku'] ?? null,
        ]);

        $result = parent::createProduct($data);
        $this->emit('CREATE_RESULT', [
            'status' => (int)($result['status'] ?? 0),
            'error' => (string)($result['error'] ?? ''),
            'body' => $result['body'] ?? [],
        ]);
        return $result;
    }

    private function emit(string $label, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }
        echo $label . '_B64=' . base64_encode($json) . PHP_EOL;
    }
}

$wc = new WooCommerceClient();
$basalam = new DebugBasalamClient2040();
$sync = new BasalamSync($wc, $basalam);
$result = $sync->syncProduct(2040, true);

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    $json = '{}';
}
echo 'SYNC_RESULT_B64=' . base64_encode($json) . PHP_EOL;
