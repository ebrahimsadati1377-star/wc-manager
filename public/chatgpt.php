<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$newToken = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $error = 'نشست شما نامعتبر است. صفحه را رفرش کنید.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'generate') {
            $newToken = 'wcm_' . bin2hex(random_bytes(32));
            setSetting('chatgpt_api_token_hash', hash('sha256', $newToken));
            logActivity('chatgpt_api_token_generate', 'chatgpt_api');
        } elseif ($action === 'revoke') {
            setSetting('chatgpt_api_token_hash', '');
            logActivity('chatgpt_api_token_revoke', 'chatgpt_api');
        }
    }
}

$pageTitle = 'اتصال ChatGPT';
require __DIR__ . '/partials/header.php';
?>

<h3 class="mb-4">اتصال ChatGPT</h3>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($newToken): ?>
  <div class="alert alert-warning">
    <strong>این توکن فقط همین یک‌بار نمایش داده می‌شود.</strong>
    آن را در محل امن ذخیره کنید.
  </div>
  <div class="input-group mb-4" dir="ltr">
    <input class="form-control font-monospace" value="<?= e($newToken) ?>" readonly onclick="this.select()">
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <p>
      وضعیت:
      <?php if (trim((string)getSetting('chatgpt_api_token_hash', '')) !== ''): ?>
        <span class="badge text-bg-success">توکن فعال است</span>
      <?php elseif (getenv('WC_MANAGER_API_TOKEN')): ?>
        <span class="badge text-bg-success">توکن از Environment فعال است</span>
      <?php else: ?>
        <span class="badge text-bg-secondary">توکن تنظیم نشده</span>
      <?php endif; ?>
    </p>

    <p class="text-muted">
      کلید Consumer Key/Secret ووکامرس در سرور باقی می‌ماند. ChatGPT فقط با یک Bearer Token مستقل به API این پنل دسترسی خواهد داشت.
    </p>

    <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="generate">
      <button class="btn btn-primary" type="submit">ساخت / تعویض توکن</button>
    </form>

    <form method="post" class="d-inline ms-2" onsubmit="return confirm('توکن فعلی باطل شود؟')">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="revoke">
      <button class="btn btn-outline-danger" type="submit">ابطال توکن</button>
    </form>
  </div>
</div>

<div class="card mt-4">
  <div class="card-body">
    <h5>آدرس‌ها</h5>
    <div dir="ltr" class="font-monospace small">
      <div><?= e(currentUrl()) ?>/api/health.php</div>
      <div><?= e(currentUrl()) ?>/openapi-chatgpt.yaml</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
