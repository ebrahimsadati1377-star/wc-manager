<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/OpenAIAgent.php';
require_once __DIR__ . '/../../includes/ProductAiSeoService.php';
Auth::requireLogin();
requirePostAndCsrfOrFail();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) jsonResponse(['success'=>false,'message'=>'داده نامعتبر است.'], 422);

try {
    $service = new ProductAiSeoService();
    $analysis = $service->analyze((string)($data['image_url'] ?? ''), [
        'rough_name'=>(string)($data['name'] ?? ''),
        'short_description'=>(string)($data['short_description'] ?? ''),
        'description'=>(string)($data['description'] ?? ''),
        'notes'=>(string)($data['notes'] ?? ''),
        'category_ids'=>(array)($data['category_ids'] ?? []),
    ]);
    jsonResponse(['success'=>true,'analysis'=>$analysis,'attributes'=>ProductAiSeoService::buildAttributes($analysis)]);
} catch (Throwable $e) {
    error_log('[wc-manager] product AI analyze: '.$e->getMessage());
    jsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
}
