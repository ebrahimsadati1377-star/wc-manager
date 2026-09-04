<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ChatGPTApi.php';
Auth::requireAdmin();

$newToken = null;
$newTokenName = '';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $error = 'نشست شما نامعتبر است. صفحه را رفرش کنید.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'generate' || $action === 'generate_multi') {
                $name = trim((string)($_POST['token_name'] ?? 'ChatGPT client'));
                $created = chatgptApiCreateToken($name);
                $newToken = (string)$created['token'];
                $newTokenName = (string)$created['record']['name'];
                logActivity('chatgpt_api_token_generate', 'chatgpt_api', 'client=' . (string)$created['record']['id'] . ' name=' . $newTokenName);
            } elseif ($action === 'revoke_client') {
                $id = trim((string)($_POST['token_id'] ?? ''));
                if ($id === '' || !chatgptApiRevokeStoredToken($id)) {
                    throw new RuntimeException('توکن موردنظر پیدا نشد.');
                }
                logActivity('chatgpt_api_token_revoke', 'chatgpt_api', 'client=' . $id);
                $success = 'توکن انتخاب‌شده باطل شد.';
            } elseif ($action === 'revoke_legacy' || $action === 'revoke') {
                setSetting('chatgpt_api_token_hash', '');
                logActivity('chatgpt_api_token_revoke', 'chatgpt_api', 'client=legacy');
                $success = 'توکن قدیمی باطل شد.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$tokens = chatgptApiStoredTokens();
$legacyActive = preg_match('/^[a-f0-9]{64}$/i', trim((string)getSetting('chatgpt_api_token_hash', ''))) === 1;
$envActive = is_string(getenv('WC_MANAGER_API_TOKEN')) && trim((string)getenv('WC_MANAGER_API_TOKEN')) !== '';

$pageTitle = 'اتصال ChatGPT / API Agent';
require __DIR__ . '/partials/header.php';
?>

<h3 class="mb-4">اتصال ChatGPT / API Agent</h3>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($newToken): ?>
  <div class="alert alert-warning">
    <strong>توکن «<?= e($newTokenName) ?>» فقط همین یک‌بار نمایش داده می‌شود.</strong>
    آن را در محل امن ذخیره کنید. خود توکن در دیتابیس ذخیره نمی‌شود و فقط SHA-256 آن نگه‌داری می‌شود.
  </div>
  <div class="input-group mb-4" dir="ltr">
    <input class="form-control font-monospace" value="<?= e($newToken) ?>" readonly onclick="this.select()">
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body">
    <h5 class="mb-3">ساخت کلاینت جدید</h5>
    <p class="text-muted">برای هر ChatGPT، Agent یا شخص یک توکن جدا بسازید تا بعداً بتوانید فقط همان دسترسی را باطل کنید.</p>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="generate_multi">
      <div class="col-md-7">
        <label class="form-label">نام کلاینت</label>
        <input class="form-control" name="token_name" maxlength="80" placeholder="مثلاً ChatGPT Client 2" required>
      </div>
      <div class="col-md-5">
        <button class="btn btn-primary" type="submit">ساخت توکن مستقل</button>
      </div>
    </form>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <h5 class="mb-3">توکن‌های فعال</h5>
    <?php if (!$tokens && !$legacyActive && !$envActive): ?>
      <span class="badge text-bg-secondary">توکن تنظیم نشده</span>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>نام</th><th>شناسه</th><th>ساخته‌شده</th><th>انتهای توکن</th><th></th></tr></thead>
          <tbody>
          <?php if ($envActive): ?>
            <tr>
              <td>Environment token</td><td><code>environment</code></td><td>-</td><td>-</td>
              <td><span class="text-muted small">از Environment مدیریت می‌شود</span></td>
            </tr>
          <?php endif; ?>
          <?php if ($legacyActive): ?>
            <tr>
              <td>Legacy token</td><td><code>legacy</code></td><td>-</td><td>-</td>
              <td>
                <form method="post" onsubmit="return confirm('توکن قدیمی باطل شود؟')">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="revoke_legacy">
                  <button class="btn btn-sm btn-outline-danger" type="submit">ابطال</button>
                </form>
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($tokens as $token): ?>
            <tr>
              <td><?= e($token['name']) ?></td>
              <td><code><?= e($token['id']) ?></code></td>
              <td dir="ltr"><?= e($token['created_at'] ?: '-') ?></td>
              <td dir="ltr"><?= $token['last4'] ? '…' . e($token['last4']) : '-' ?></td>
              <td>
                <form method="post" onsubmit="return confirm('این توکن باطل شود؟')">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="revoke_client">
                  <input type="hidden" name="token_id" value="<?= e($token['id']) ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">ابطال</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <h5>آدرس‌ها</h5>
    <div dir="ltr" class="font-monospace small mb-3">
      <div><?= e(currentUrl()) ?>/api/health.php</div>
      <div><?= e(currentUrl()) ?>/openapi-chatgpt.yaml</div>
      <div><?= e(currentUrl()) ?>/agent.php</div>
    </div>
    <p class="text-muted mb-2">Consumer Key/Secret ووکامرس و توکن باسلام روی سرور باقی می‌مانند. کلاینت‌ها فقط Bearer Token مستقل خودشان را دارند.</p>
    <a class="btn btn-outline-primary" href="agent.php">باز کردن API Agent آزمایشی</a>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
