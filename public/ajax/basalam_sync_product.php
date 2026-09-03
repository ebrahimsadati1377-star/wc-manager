<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

$id = (int)($_POST['id'] ?? 0);
$force = !empty($_POST['force']);

if ($id <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه محصول نامعتبر است.'], 422);
}

$sync = new BasalamSync();
$result = $sync->syncProduct($id, $force);
jsonResponse($result, $result['success'] ? 200 : 422);
