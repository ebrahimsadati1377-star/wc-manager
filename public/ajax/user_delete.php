<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireAdmin();
requireCsrfOrFail();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.']);
}
if ($id === (int)Auth::user()['id']) {
    jsonResponse(['success' => false, 'message' => 'نمی‌توانید حساب کاربری خودتان را حذف کنید.']);
}

$stmt = Database::get()->prepare('DELETE FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);

logActivity('delete_user', 'user', (string)$id);
jsonResponse(['success' => true]);
