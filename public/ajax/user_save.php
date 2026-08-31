<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireAdmin();
requireCsrfOrFail();

$id = (int)($_POST['id'] ?? 0);
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = (string)($_POST['password'] ?? '');
$role = ($_POST['role'] ?? 'editor') === 'admin' ? 'admin' : 'editor';
$isActive = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;

if ($fullName === '' || $username === '') {
    jsonResponse(['success' => false, 'message' => 'نام و نام کاربری الزامی است.']);
}
if ($id === 0 && $password === '') {
    jsonResponse(['success' => false, 'message' => 'برای کاربر جدید، رمز عبور الزامی است.']);
}
if ($password !== '' && strlen($password) < 6) {
    jsonResponse(['success' => false, 'message' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']);
}

$db = Database::get();

// یکتا بودن نام کاربری
$check = $db->prepare('SELECT id FROM users WHERE username = :u AND id != :id');
$check->execute(['u' => $username, 'id' => $id]);
if ($check->fetch()) {
    jsonResponse(['success' => false, 'message' => 'این نام کاربری قبلاً استفاده شده است.']);
}

if ($id > 0) {
    if ($password !== '') {
        $stmt = $db->prepare('UPDATE users SET full_name=:fn, username=:un, role=:r, is_active=:ia, password_hash=:ph WHERE id=:id');
        $stmt->execute([
            'fn' => $fullName, 'un' => $username, 'r' => $role, 'ia' => $isActive,
            'ph' => password_hash($password, PASSWORD_BCRYPT), 'id' => $id,
        ]);
    } else {
        $stmt = $db->prepare('UPDATE users SET full_name=:fn, username=:un, role=:r, is_active=:ia WHERE id=:id');
        $stmt->execute(['fn' => $fullName, 'un' => $username, 'r' => $role, 'ia' => $isActive, 'id' => $id]);
    }
    logActivity('update_user', 'user', $username);
} else {
    $stmt = $db->prepare('INSERT INTO users (full_name, username, password_hash, role, is_active) VALUES (:fn, :un, :ph, :r, :ia)');
    $stmt->execute([
        'fn' => $fullName, 'un' => $username, 'ph' => password_hash($password, PASSWORD_BCRYPT),
        'r' => $role, 'ia' => $isActive,
    ]);
    logActivity('create_user', 'user', $username);
}

jsonResponse(['success' => true]);
