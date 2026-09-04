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
$activeCount = count($tokens) + ($legacyActive ? 1 : 0) + ($envActive ? 1 : 0);

$pageTitle = 'اتصال ChatGPT / API Agent';
require __DIR__ . '/partials/header.php';
?>

<style>
.api-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr);gap:1rem;align-items:start}.api-endpoint{min-width:0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.75rem .85rem;border:1px solid #e8edf3;border-radius:12px;background:#fafcff}.api-endpoint code{direction:ltr;display:block;max-width:100%;overflow:auto;font-size:.72rem;overflow-wrap:anywhere;word-break:break-all}.api-token-once{border:1px solid #f5c451;background:#fffaf0;border-radius:16px;padding:1rem}.api-token-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.5rem;margin-top:.75rem}.api-security-list{display:grid;gap:.8rem}.api-security-item{display:flex;gap:.65rem;align-items:flex-start}.api-security-item i{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;background:#eff6ff;color:#2563eb;flex:0 0 32px}.api-security-item strong{display:block;font-size:.78rem}.api-security-item span{display:block;color:#7c8797;font-size:.7rem;line-height:1.7;margin-top:.12rem}@media(max-width:991.98px){.api-layout{grid-template-columns:1fr}}@media(max-width:767.98px){.api-token-row{grid-template-columns:1fr}.api-endpoint{align-items:flex-start;flex-direction:column}.api-endpoint .btn{width:100%}}
</style>

<div class="app-page-head">
  <div class="app-page-head__copy">
    <div class="app-page-head__eyebrow"><i class="fas fa-robot"></i> API و اتوماسیون</div>
    <h1 class="app-page-head__title">اتصال ChatGPT و Agentها</h1>
    <p class="app-page-head__subtitle">برای هر کلاینت یک Bearer Token مستقل بسازید تا کنترل دسترسی و ابطال آن ساده و قابل پیگیری باشد.</p>
  </div>
  <div class="app-page-head__actions"><span class="app-meta-chip"><span class="app-status-dot <?= $activeCount > 0 ? 'success' : 'warning' ?>"></span><?= $activeCount ?> دسترسی فعال</span></div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation ms-1"></i><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-circle-check ms-1"></i><?= e($success) ?></div><?php endif; ?>

<?php if ($newToken): ?>
  <div class="api-token-once mb-3">
    <div class="d-flex gap-2 align-items-start"><i class="fas fa-key text-warning mt-1"></i><div><strong>توکن «<?= e($newTokenName) ?>» فقط همین یک‌بار نمایش داده می‌شود.</strong><div class="text-muted small mt-1">بعد از بستن یا تازه‌سازی صفحه امکان نمایش مجدد آن وجود ندارد.</div></div></div>
    <div class="api-token-row" dir="ltr"><input id="newApiToken" class="form-control font-monospace" value="<?= e($newToken) ?>" readonly onclick="this.select()"><button type="button" class="btn btn-dark" id="copyApiToken"><i class="far fa-copy me-1"></i> Copy</button></div>
  </div>
<?php endif; ?>

<div class="api-layout">
  <div class="d-grid gap-3">
    <section class="app-section-card">
      <div class="app-section-card__head"><div><h2>ساخت کلاینت جدید</h2><p>برای هر ChatGPT، Agent یا اپلیکیشن یک توکن جدا بسازید.</p></div><i class="fas fa-plus-circle text-primary"></i></div>
      <div class="app-section-card__body">
        <form method="post" class="row g-3 align-items-end">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="generate_multi">
          <div class="col-md-8"><label class="form-label">نام کلاینت</label><input class="form-control" name="token_name" maxlength="80" placeholder="مثلاً ChatGPT فروشگاه" required><div class="form-text">نامی انتخاب کنید که بعداً مشخص باشد این توکن متعلق به کدام کلاینت است.</div></div>
          <div class="col-md-4 d-grid"><button class="btn btn-primary" type="submit"><i class="fas fa-key ms-1"></i>ساخت توکن</button></div>
        </form>
      </div>
    </section>

    <section class="app-section-card">
      <div class="app-section-card__head"><div><h2>توکن‌های فعال</h2><p>توکن‌های اضافی را حذف کنید تا سطح دسترسی حداقلی بماند.</p></div><span class="app-meta-chip"><?= $activeCount ?> مورد</span></div>
      <?php if (!$tokens && !$legacyActive && !$envActive): ?>
        <div class="app-empty-state"><div class="app-empty-state__icon"><i class="fas fa-key"></i></div><h4>توکن فعالی وجود ندارد</h4><p>برای اتصال اولین کلاینت، یک توکن مستقل بسازید.</p></div>
      <?php else: ?>
      <div class="table-responsive app-desktop-table">
        <table class="table align-middle mb-0">
          <thead><tr><th>نام</th><th>شناسه</th><th>ساخته‌شده</th><th>انتهای توکن</th><th class="text-end">عملیات</th></tr></thead>
          <tbody>
          <?php if ($envActive): ?><tr><td><strong>Environment token</strong><div class="text-muted small">مدیریت از Environment</div></td><td><code>environment</code></td><td>—</td><td>—</td><td class="text-end"><span class="badge text-bg-success">فعال</span></td></tr><?php endif; ?>
          <?php if ($legacyActive): ?><tr><td><strong>Legacy token</strong><div class="text-muted small">نسخه قدیمی</div></td><td><code>legacy</code></td><td>—</td><td>—</td><td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('توکن قدیمی باطل شود؟')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="revoke_legacy"><button class="btn btn-sm btn-outline-danger" type="submit">ابطال</button></form></td></tr><?php endif; ?>
          <?php foreach ($tokens as $token): ?>
            <tr><td><strong><?= e($token['name']) ?></strong></td><td><code><?= e($token['id']) ?></code></td><td dir="ltr" class="text-muted small"><?= e($token['created_at'] ?: '-') ?></td><td dir="ltr"><?= $token['last4'] ? '…' . e($token['last4']) : '-' ?></td><td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('این توکن باطل شود؟')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="revoke_client"><input type="hidden" name="token_id" value="<?= e($token['id']) ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="fas fa-ban ms-1"></i>ابطال</button></form></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="app-mobile-list p-2">
        <?php if ($envActive): ?>
          <div class="app-mobile-card">
            <div class="app-mobile-card__top"><div class="app-mobile-card__main"><div class="app-mobile-card__title">Environment token</div><div class="app-mobile-card__meta"><span>مدیریت از Environment</span><span class="badge text-bg-success">فعال</span></div></div></div>
          </div>
        <?php endif; ?>
        <?php if ($legacyActive): ?>
          <div class="app-mobile-card">
            <div class="app-mobile-card__top"><div class="app-mobile-card__main"><div class="app-mobile-card__title">Legacy token</div><div class="app-mobile-card__meta"><span dir="ltr">legacy</span><span>نسخه قدیمی</span></div></div></div>
            <div class="app-mobile-card__actions"><form method="post" class="d-grid" onsubmit="return confirm('توکن قدیمی باطل شود؟')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="revoke_legacy"><button class="btn btn-outline-danger" type="submit"><i class="fas fa-ban ms-1"></i>ابطال</button></form></div>
          </div>
        <?php endif; ?>
        <?php foreach ($tokens as $token): ?>
          <div class="app-mobile-card">
            <div class="app-mobile-card__top"><div class="app-mobile-card__main"><div class="app-mobile-card__title"><?= e($token['name']) ?></div><div class="app-mobile-card__meta"><span dir="ltr" style="overflow-wrap:anywhere"><?= e($token['id']) ?></span><?php if ($token['last4']): ?><span dir="ltr">…<?= e($token['last4']) ?></span><?php endif; ?><span dir="ltr"><?= e($token['created_at'] ?: '-') ?></span></div></div></div>
            <div class="app-mobile-card__actions"><form method="post" class="d-grid" onsubmit="return confirm('این توکن باطل شود؟')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="revoke_client"><input type="hidden" name="token_id" value="<?= e($token['id']) ?>"><button class="btn btn-outline-danger" type="submit"><i class="fas fa-ban ms-1"></i>ابطال</button></form></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="app-section-card">
      <div class="app-section-card__head"><div><h2>Endpointها</h2><p>آدرس‌های موردنیاز برای تنظیم کلاینت و بررسی سلامت API.</p></div></div>
      <div class="app-section-card__body d-grid gap-2">
        <?php foreach ([['Health','/api/health.php'],['OpenAPI Schema','/openapi-chatgpt.yaml'],['API Agent','/agent.php']] as [$label,$path]): ?>
          <div class="api-endpoint"><div><strong class="d-block small mb-1"><?= e($label) ?></strong><code><?= e(currentUrl() . $path) ?></code></div><?php if ($path === '/agent.php'): ?><a class="btn btn-sm btn-outline-primary" href="agent.php">باز کردن</a><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <aside class="d-grid gap-3">
    <section class="app-section-card">
      <div class="app-section-card__head"><div><h2>مدل امنیتی</h2></div><i class="fas fa-shield-halved text-primary"></i></div>
      <div class="app-section-card__body api-security-list">
        <div class="api-security-item"><i class="fas fa-server"></i><div><strong>Credentialهای اصلی روی سرور می‌مانند</strong><span>Consumer Secret ووکامرس و توکن باسلام به کلاینت ChatGPT داده نمی‌شوند.</span></div></div>
        <div class="api-security-item"><i class="fas fa-fingerprint"></i><div><strong>هر کلاینت توکن مستقل دارد</strong><span>می‌توانید یک دسترسی خاص را بدون تأثیر روی بقیه کلاینت‌ها باطل کنید.</span></div></div>
        <div class="api-security-item"><i class="fas fa-eye-slash"></i><div><strong>توکن خام ذخیره نمی‌شود</strong><span>در دیتابیس فقط هش توکن نگه‌داری می‌شود.</span></div></div>
      </div>
    </section>
  </aside>
</div>

<script>
document.getElementById('copyApiToken')?.addEventListener('click', async function(){
  const input = document.getElementById('newApiToken');
  if (!input) return;
  try { await navigator.clipboard.writeText(input.value); this.innerHTML = '<i class="fas fa-check me-1"></i> Copied'; }
  catch (_) { input.select(); document.execCommand('copy'); this.textContent = 'Copied'; }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
