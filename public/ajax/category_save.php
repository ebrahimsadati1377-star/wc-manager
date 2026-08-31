<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$parent = (int)($_POST['parent'] ?? 0);
$description = trim($_POST['description'] ?? '');
$imageUrl = trim($_POST['image_url'] ?? '');

if ($name === '') {
    jsonResponse(['success' => false, 'message' => 'نام دسته‌بندی الزامی است.']);
}

$payload = [
    'name'        => $name,
    'parent'      => $parent,
    'description' => $description,
];
if ($imageUrl !== '') {
    $payload['image'] = ['src' => $imageUrl];
}

$wc = new WooCommerceClient();
$res = $id > 0 ? $wc->updateCategory($id, $payload) : $wc->createCategory($payload);

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => $res['error']]);
}

logActivity($id > 0 ? 'update_category' : 'create_category', 'category', $name);
jsonResponse(['success' => true, 'category' => $res['body']]);
