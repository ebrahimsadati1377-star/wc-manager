<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$stmt = Database::get()->query('SELECT id, full_name, username, role, is_active, created_at FROM users ORDER BY id ASC');
$users = $stmt->fetchAll();

$pageTitle = 'کاربران';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0">مدیریت کاربران پنل</h3>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openUserModal()">➕ کاربر جدید</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>نام</th>
          <th>نام کاربری</th>
          <th>نقش</th>
          <th>وضعیت</th>
          <th>تاریخ ایجاد</th>
          <th class="text-end">عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['full_name']) ?></td>
          <td dir="ltr"><?= e($u['username']) ?></td>
          <td><span class="badge <?= $u['role'] === 'admin' ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= $u['role'] === 'admin' ? 'مدیر' : 'ویرایشگر' ?></span></td>
          <td><?= $u['is_active'] ? '<span class="badge text-bg-success">فعال</span>' : '<span class="badge text-bg-secondary">غیرفعال</span>' ?></td>
          <td class="small text-muted"><?= e($u['created_at']) ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary" onclick='openUserModal(<?= json_encode($u, JSON_UNESCAPED_UNICODE) ?>)'>ویرایش</button>
            <?php if ((int)$u['id'] !== (int)Auth::user()['id']): ?>
              <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>')">حذف</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="userForm">
        <div class="modal-header">
          <h5 class="modal-title" id="userModalTitle">کاربر جدید</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="u_id">
          <div class="mb-3">
            <label class="form-label">نام کامل</label>
            <input type="text" class="form-control" name="full_name" id="u_full_name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">نام کاربری</label>
            <input type="text" class="form-control" name="username" id="u_username" dir="ltr" required>
          </div>
          <div class="mb-3">
            <label class="form-label">رمز عبور <span id="pwHint" class="text-muted small"></span></label>
            <input type="password" class="form-control" name="password" id="u_password">
          </div>
          <div class="mb-3">
            <label class="form-label">نقش</label>
            <select class="form-select" name="role" id="u_role">
              <option value="editor">ویرایشگر (فقط محصولات و دسته‌بندی‌ها)</option>
              <option value="admin">مدیر سیستم (دسترسی کامل)</option>
            </select>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="is_active" id="u_is_active" checked>
            <label class="form-check-label" for="u_is_active">فعال</label>
          </div>
          <div id="userFormError" class="text-danger small mt-2"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
          <button type="submit" class="btn btn-primary">ذخیره</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="assets/js/users.js"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
