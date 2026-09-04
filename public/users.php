<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$stmt = Database::get()->query('SELECT id, full_name, username, role, is_active, created_at FROM users ORDER BY id ASC');
$users = $stmt->fetchAll();
$activeUsers = count(array_filter($users, static fn(array $u): bool => (bool)$u['is_active']));
$adminUsers = count(array_filter($users, static fn(array $u): bool => ($u['role'] ?? '') === 'admin'));

$pageTitle = 'کاربران';
require __DIR__ . '/partials/header.php';
?>

<div class="app-page-head">
  <div class="app-page-head__copy">
    <div class="app-page-head__eyebrow"><i class="fas fa-users"></i> دسترسی و امنیت</div>
    <h1 class="app-page-head__title">کاربران پنل</h1>
    <p class="app-page-head__subtitle">دسترسی اعضای تیم به محصولات، محتوا و تنظیمات سیستم را مدیریت کنید.</p>
  </div>
  <div class="app-page-head__actions">
    <span class="app-meta-chip"><span class="app-status-dot success"></span><?= $activeUsers ?> فعال</span>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openUserModal()"><i class="fas fa-user-plus ms-1"></i> کاربر جدید</button>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="app-section-card h-100"><div class="app-section-card__body"><div class="text-muted small mb-1">کل کاربران</div><div class="fs-4 fw-bold"><?= count($users) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="app-section-card h-100"><div class="app-section-card__body"><div class="text-muted small mb-1">مدیران</div><div class="fs-4 fw-bold"><?= $adminUsers ?></div></div></div></div>
</div>

<div class="app-section-card">
  <div class="app-section-card__head">
    <div><h2>اعضای دارای دسترسی</h2><p>نقش و وضعیت هر کاربر از همین بخش قابل ویرایش است.</p></div>
  </div>
  <div class="table-responsive app-desktop-table">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr><th>کاربر</th><th>نام کاربری</th><th>نقش</th><th>وضعیت</th><th>تاریخ ایجاد</th><th class="text-end">عملیات</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-3 d-grid place-items-center bg-light text-secondary fw-bold" style="width:38px;height:38px;place-items:center"><?= e(function_exists('mb_substr') ? mb_substr((string)$u['full_name'],0,1,'UTF-8') : substr((string)$u['full_name'],0,1)) ?></div>
              <div><div class="fw-semibold"><?= e($u['full_name']) ?></div><?php if ((int)$u['id'] === (int)Auth::user()['id']): ?><small class="text-muted">حساب شما</small><?php endif; ?></div>
            </div>
          </td>
          <td dir="ltr" class="text-muted"><?= e($u['username']) ?></td>
          <td><span class="badge <?= $u['role'] === 'admin' ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= $u['role'] === 'admin' ? 'مدیر' : 'ویرایشگر' ?></span></td>
          <td><?= $u['is_active'] ? '<span class="badge text-bg-success">فعال</span>' : '<span class="badge text-bg-secondary">غیرفعال</span>' ?></td>
          <td class="small text-muted" dir="ltr"><?= e($u['created_at']) ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary" onclick='openUserModal(<?= json_encode($u, JSON_UNESCAPED_UNICODE) ?>)'><i class="fas fa-pen ms-1"></i>ویرایش</button>
            <?php if ((int)$u['id'] !== (int)Auth::user()['id']): ?>
              <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>')"><i class="fas fa-trash ms-1"></i>حذف</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="app-mobile-list p-2">
    <?php foreach ($users as $u): ?>
      <div class="app-mobile-card">
        <div class="app-mobile-card__top">
          <div class="rounded-3 d-grid bg-light text-secondary fw-bold" style="width:42px;height:42px;place-items:center;flex:0 0 42px"><?= e(function_exists('mb_substr') ? mb_substr((string)$u['full_name'],0,1,'UTF-8') : substr((string)$u['full_name'],0,1)) ?></div>
          <div class="app-mobile-card__main">
            <div class="app-mobile-card__title"><?= e($u['full_name']) ?></div>
            <div class="app-mobile-card__meta"><span dir="ltr"><?= e($u['username']) ?></span><span><?= $u['role'] === 'admin' ? 'مدیر' : 'ویرایشگر' ?></span><span><?= $u['is_active'] ? 'فعال' : 'غیرفعال' ?></span></div>
          </div>
        </div>
        <div class="app-mobile-card__actions">
          <button class="btn btn-outline-primary" onclick='openUserModal(<?= json_encode($u, JSON_UNESCAPED_UNICODE) ?>)'><i class="fas fa-pen ms-1"></i>ویرایش</button>
          <?php if ((int)$u['id'] !== (int)Auth::user()['id']): ?>
            <button class="btn btn-outline-danger" onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>')"><i class="fas fa-trash ms-1"></i>حذف</button>
          <?php else: ?>
            <button class="btn btn-outline-secondary" disabled>حساب فعلی</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="userForm">
        <div class="modal-header">
          <div><h5 class="modal-title" id="userModalTitle">کاربر جدید</h5><div class="text-muted small mt-1">سطح دسترسی را متناسب با مسئولیت کاربر انتخاب کنید.</div></div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="u_id">
          <div class="mb-3"><label class="form-label">نام کامل</label><input type="text" class="form-control" name="full_name" id="u_full_name" required></div>
          <div class="mb-3"><label class="form-label">نام کاربری</label><input type="text" class="form-control" name="username" id="u_username" dir="ltr" autocomplete="username" required></div>
          <div class="mb-3"><label class="form-label">رمز عبور <span id="pwHint" class="text-muted small"></span></label><input type="password" class="form-control" name="password" id="u_password" autocomplete="new-password"></div>
          <div class="mb-3">
            <label class="form-label">نقش</label>
            <select class="form-select" name="role" id="u_role">
              <option value="editor">ویرایشگر — محصولات، دسته‌بندی‌ها و محتوا</option>
              <option value="admin">مدیر سیستم — دسترسی کامل</option>
            </select>
          </div>
          <div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="is_active" id="u_is_active" checked><label class="form-check-label" for="u_is_active">حساب فعال باشد</label></div>
          <div id="userFormError" class="text-danger small mt-2"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-primary"><i class="fas fa-check ms-1"></i>ذخیره کاربر</button></div>
      </form>
    </div>
  </div>
</div>

<script src="assets/js/users.js"></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
