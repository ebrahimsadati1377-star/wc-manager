<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/RubikaClient.php';
Auth::requireAdmin();

$detectedChats = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!checkCsrf()) {
        setFlash('danger', 'نشست منقضی شده، دوباره تلاش کنید.');
        redirect('rubika.php');
    }

    $action = trim((string)($_POST['action'] ?? 'save'));
    $token = trim((string)($_POST['rubika_bot_token'] ?? ''));
    $channelId = trim((string)($_POST['rubika_channel_id'] ?? ''));
    $enabled = !empty($_POST['rubika_auto_publish_enabled']) ? '1' : '0';

    if ($token === '') {
        $token = (string)getSetting('rubika_bot_token', '');
    }
    if ($channelId === '') {
        $channelId = (string)getSetting('rubika_channel_id', '');
    }

    setSetting('rubika_bot_token', $token);
    setSetting('rubika_channel_id', $channelId);
    setSetting('rubika_auto_publish_enabled', $enabled);

    if ((string)getSetting('woocommerce_rubika_webhook_secret', '') === '') {
        setSetting('woocommerce_rubika_webhook_secret', bin2hex(random_bytes(32)));
    }

    $rubika = new RubikaClient($token, $channelId);

    if ($action === 'detect') {
        $recent = $rubika->getRecentChats();
        if (empty($recent['ok'])) {
            setFlash('danger', 'دریافت شناسه‌های روبیکا ناموفق بود: ' . (string)($recent['error'] ?? 'خطای نامشخص'));
        } else {
            $detectedChats = is_array($recent['result'] ?? null) ? $recent['result'] : [];
            if ($detectedChats) {
                $_SESSION['rubika_detected_chats'] = $detectedChats;
                setFlash('success', 'شناسه‌های اخیر روبیکا دریافت شدند؛ یکی را برای کانال BAJI انتخاب کنید.');
            } else {
                setFlash('warning', 'هنوز هیچ Chat ID در آپدیت‌های ربات دیده نشد. ربات را به کانال اضافه کنید و یک پیام جدید در کانال بفرستید، سپس دوباره امتحان کنید.');
            }
        }
        redirect('rubika.php');
    }

    if ($action === 'test') {
        $me = $rubika->getMe();
        if (empty($me['ok'])) {
            setFlash('danger', 'توکن روبیکا معتبر نیست یا API روبیکا در دسترس نیست.');
        } else {
            $test = $rubika->sendMessage('✅ اتصال کانال BAJI به WC Manager با موفقیت تست شد.');
            if (!empty($test['ok'])) {
                setFlash('success', 'پیام تست با موفقیت در کانال روبیکا ارسال شد.');
            } else {
                setFlash('warning', 'توکن معتبر است، اما ارسال به کانال انجام نشد. ربات باید عضو و مدیر کانال باشد.');
            }
        }
        redirect('rubika.php');
    }

    if ($enabled === '1' && $rubika->isConfigured()) {
        $hooks = RubikaClient::ensureWooWebhooks();
        if (!empty($hooks['success'])) {
            setFlash('success', 'تنظیمات روبیکا ذخیره شد و وبهوک‌های انتشار محصول فعال هستند.');
        } else {
            setFlash('warning', 'تنظیمات ذخیره شد؛ ساخت وبهوک ووکامرس نیاز به بررسی دارد.');
        }
    } else {
        setFlash('success', 'تنظیمات روبیکا ذخیره شد.');
    }
    logActivity('update_rubika_settings', 'rubika', 'channel=' . $channelId . '; enabled=' . $enabled);
    redirect('rubika.php');
}

$detectedChats = is_array($_SESSION['rubika_detected_chats'] ?? null)
    ? $_SESSION['rubika_detected_chats']
    : [];
unset($_SESSION['rubika_detected_chats']);

$pageTitle = 'اتصال روبیکا';
require __DIR__ . '/partials/header.php';

$configured = (string)getSetting('rubika_bot_token', '') !== ''
    && (string)getSetting('rubika_channel_id', '') !== '';
$enabled = (string)getSetting('rubika_auto_publish_enabled', '0') === '1';
$channelId = (string)getSetting('rubika_channel_id', '');
$webhookUrl = rtrim(currentUrl(), '/') . '/webhooks/woocommerce-rubika-sync.php';
?>

<style>
.rubika-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:1rem;align-items:start}
.rubika-status{display:flex;align-items:center;gap:.7rem;padding:1rem;border:1px solid #e8edf3;border-radius:14px;background:#f8fafc}
.rubika-status i{width:42px;height:42px;display:grid;place-items:center;border-radius:12px;background:#e7f7ef;color:#15803d}
.rubika-code{direction:ltr;text-align:left;word-break:break-all;background:#f8fafc;border:1px solid #e8edf3;border-radius:10px;padding:.7rem;font-size:.78rem}
@media(max-width:900px){.rubika-grid{grid-template-columns:1fr}}
</style>

<div class="app-page-head">
  <div class="app-page-head__copy">
    <div class="app-page-head__eyebrow"><i class="fas fa-paper-plane"></i> انتشار خودکار</div>
    <h1 class="app-page-head__title">اتصال کانال روبیکا BAJI</h1>
    <p class="app-page-head__subtitle">بعد از انتشار محصول در WooCommerce، عکس و جزئیات محصول خودکار در کانال روبیکا منتشر می‌شود.</p>
  </div>
</div>

<div class="rubika-grid">
  <form method="post" class="d-grid gap-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <section class="app-section-card">
      <div class="app-section-card__head">
        <div><h2>ربات روبیکا</h2><p>توکن فقط روی سرور ذخیره می‌شود و دوباره در صفحه نمایش داده نمی‌شود.</p></div>
        <i class="fas fa-robot text-primary"></i>
      </div>
      <div class="app-section-card__body">
        <div class="mb-3">
          <label class="form-label">توکن ربات</label>
          <input type="password" name="rubika_bot_token" class="form-control" dir="ltr"
                 autocomplete="new-password" placeholder="برای حفظ توکن فعلی خالی بگذارید">
        </div>
        <div>
          <label class="form-label">Chat ID کانال</label>
          <input type="text" name="rubika_channel_id" class="form-control" dir="ltr"
                 value="<?= e($channelId) ?>" placeholder="مثلاً c0...">
          <div class="form-text">شناسه چت/کانال روبیکا را وارد کنید. این مقدار لزوماً با نام کاربری کانال یکی نیست.</div>
        </div>

        <?php if ($detectedChats): ?>
          <div class="mt-3 p-3 rounded-3 border bg-light">
            <div class="fw-bold mb-2">Chat IDهای پیدا شده</div>
            <div class="d-grid gap-2">
              <?php foreach ($detectedChats as $chat): ?>
                <?php $detectedId = trim((string)($chat['chat_id'] ?? '')); ?>
                <?php if ($detectedId !== ''): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary text-start rubika-chat-choice"
                          data-chat-id="<?= e($detectedId) ?>">
                    <span dir="ltr"><?= e($detectedId) ?></span>
                    <?php if (trim((string)($chat['text'] ?? '')) !== ''): ?>
                      <span class="d-block small text-muted mt-1"><?= e(mb_substr((string)$chat['text'], 0, 90)) ?></span>
                    <?php endif; ?>
                  </button>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="app-section-card">
      <div class="app-section-card__head">
        <div><h2>انتشار خودکار محصول</h2><p>هر محصول فقط اولین بار که واقعاً منتشر شود به کانال ارسال می‌شود.</p></div>
        <i class="fas fa-bolt text-primary"></i>
      </div>

      <div class="app-section-card__body">
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="rubika_auto_publish_enabled"
                 value="1" id="rubikaAuto" <?= $enabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="rubikaAuto">انتشار خودکار محصولات جدید در روبیکا فعال باشد</label>
        </div>
        <div class="small text-muted">
          پست شامل تصویر شاخص، نام محصول، قیمت، قیمت تخفیف‌خورده، ویژگی‌ها،
          وضعیت موجودی، توضیح کوتاه و لینک مستقیم خرید است.
        </div>
      </div>
    </section>

    <div class="d-flex gap-2 flex-wrap">
      <button type="submit" name="action" value="save" class="btn btn-primary">
        <i class="fas fa-check ms-1"></i>ذخیره و فعال‌سازی
      </button>
      <button type="submit" name="action" value="detect" class="btn btn-outline-secondary">
        <i class="fas fa-magnifying-glass ms-1"></i>پیدا کردن Chat ID
      </button>
      <button type="submit" name="action" value="test" class="btn btn-outline-primary">
        <i class="fas fa-paper-plane ms-1"></i>ارسال پیام تست
      </button>
    </div>
  </form>

  <aside class="d-grid gap-3">
    <section class="app-section-card">
      <div class="app-section-card__body">
        <div class="rubika-status">
          <i class="fas fa-link"></i>
          <div>
            <strong class="d-block"><?= $configured ? 'تنظیمات ربات ثبت شده' : 'توکن هنوز ثبت نشده' ?></strong>
            <span class="small text-muted"><?= $enabled ? 'انتشار خودکار روشن است' : 'انتشار خودکار خاموش است' ?></span>
          </div>
        </div>
      </div>
    </section>

    <section class="app-section-card">
      <div class="app-section-card__head"><div><h2>Webhook</h2></div></div>
      <div class="app-section-card__body">
        <p class="small text-muted">WC Manager این آدرس را برای رویدادهای ایجاد و ویرایش محصول در WooCommerce ثبت می‌کند.</p>
        <div class="rubika-code"><?= e($webhookUrl) ?></div>
      </div>
    </section>

    <section class="app-section-card">
      <div class="app-section-card__head"><div><h2>شرط ارسال</h2></div></div>
      <div class="app-section-card__body small text-muted">
        ربات باید در کانال BAJI عضو و مدیر باشد و اجازه ارسال پست داشته باشد.
        توکن ربات از BotFather روبیکا گرفته می‌شود؛ بعد از ثبت توکن و Chat ID، همین صفحه پیام تست می‌فرستد.
      </div>
    </section>
  </aside>
</div>

<script>
document.querySelectorAll('.rubika-chat-choice').forEach((button) => {
  button.addEventListener('click', () => {
    const input = document.querySelector('input[name="rubika_channel_id"]');
    if (!input) return;
    input.value = button.dataset.chatId || '';
    input.focus();
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>