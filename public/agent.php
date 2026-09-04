<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/OpenAIAgent.php';

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

$error = null;
$answer = null;
$envOpenAi = is_string(getenv('OPENAI_API_KEY')) && trim((string)getenv('OPENAI_API_KEY')) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $error = 'نشست نامعتبر است. صفحه را رفرش کنید.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'logout') {
                unset($_SESSION['wc_agent_wc_token'], $_SESSION['wc_agent_openai_key'], $_SESSION['wc_agent_model'], $_SESSION['wc_agent_history']);
                redirect('agent.php');
            }

            if ($action === 'connect') {
                $wcToken = trim((string)($_POST['wc_token'] ?? ''));
                $openAiKey = trim((string)($_POST['openai_api_key'] ?? ''));
                $model = trim((string)($_POST['model'] ?? 'gpt-5.6-terra'));
                if ($wcToken === '') {
                    throw new RuntimeException('WC Manager Token را وارد کنید.');
                }
                if (!$envOpenAi && $openAiKey === '') {
                    throw new RuntimeException('OpenAI API Key را وارد کنید.');
                }
                if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $model)) {
                    throw new RuntimeException('نام مدل نامعتبر است.');
                }

                // Validate the WC token before storing it in the server-side session.
                wcAgentCallWcApi($wcToken, 'health.php');
                $_SESSION['wc_agent_wc_token'] = $wcToken;
                if (!$envOpenAi) {
                    $_SESSION['wc_agent_openai_key'] = $openAiKey;
                }
                $_SESSION['wc_agent_model'] = $model;
                $_SESSION['wc_agent_history'] = [];
                redirect('agent.php');
            }

            if ($action === 'chat') {
                $wcToken = trim((string)($_SESSION['wc_agent_wc_token'] ?? ''));
                $apiKey = wcAgentOpenAiKeyFromSession();
                if ($wcToken === '' || $apiKey === '') {
                    throw new RuntimeException('ابتدا اتصال Agent را انجام دهید.');
                }
                $message = trim((string)($_POST['message'] ?? ''));
                if ($message === '') {
                    throw new RuntimeException('پیام خالی است.');
                }
                if (strlen($message) > 16000) {
                    throw new RuntimeException('پیام بیش از حد طولانی است.');
                }
                $history = is_array($_SESSION['wc_agent_history'] ?? null) ? $_SESSION['wc_agent_history'] : [];
                $answer = wcAgentRun($message, $history, $wcToken, $apiKey);
                $history[] = ['role' => 'user', 'text' => $message];
                $history[] = ['role' => 'assistant', 'text' => $answer];
                $_SESSION['wc_agent_history'] = array_slice($history, -12);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$connected = trim((string)($_SESSION['wc_agent_wc_token'] ?? '')) !== '' && wcAgentOpenAiKeyFromSession() !== '';
$history = is_array($_SESSION['wc_agent_history'] ?? null) ? $_SESSION['wc_agent_history'] : [];
$pageTitle = 'Baji API Agent';

// This page is intentionally usable without a WC Manager browser account; the
// independently revocable Bearer token is the authorization boundary.
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Baji API Agent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 900px">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3 class="mb-1">Baji API Agent</h3>
      <div class="text-muted small">نسخه آزمایشی فقط خواندنی — WooCommerce و باسلام</div>
    </div>
    <?php if ($connected): ?>
      <form method="post" class="m-0">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-outline-secondary btn-sm" type="submit">قطع اتصال</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <?php if (!$connected): ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-3">اتصال کلاینت</h5>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="connect">
          <div class="mb-3">
            <label class="form-label">WC Manager Token</label>
            <input class="form-control font-monospace" type="password" name="wc_token" required autocomplete="off">
            <div class="form-text">برای هر شخص/کلاینت توکن مستقل بسازید.</div>
          </div>
          <?php if (!$envOpenAi): ?>
          <div class="mb-3">
            <label class="form-label">OpenAI API Key</label>
            <input class="form-control font-monospace" type="password" name="openai_api_key" required autocomplete="off">
            <div class="form-text">در دیتابیس ذخیره نمی‌شود؛ فقط در session همین مرورگر روی سرور نگه‌داری می‌شود.</div>
          </div>
          <?php else: ?>
            <div class="alert alert-success py-2">OPENAI_API_KEY روی سرور تنظیم شده است.</div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Model</label>
            <input class="form-control font-monospace" name="model" value="gpt-5.6-terra">
          </div>
          <button class="btn btn-primary" type="submit">اتصال و تست WC Manager</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-info">دسترسی نوشتن عمداً غیرفعال است. Agent فقط اطلاعات زنده محصولات، دسته‌ها، ویژگی‌ها و باسلام را می‌خواند.</div>

    <?php foreach ($history as $row): ?>
      <div class="card mb-2 <?= ($row['role'] ?? '') === 'assistant' ? 'border-primary' : '' ?>">
        <div class="card-body py-3">
          <div class="small text-muted mb-1"><?= ($row['role'] ?? '') === 'assistant' ? 'Agent' : 'شما' ?></div>
          <div style="white-space: pre-wrap"><?= e((string)($row['text'] ?? '')) ?></div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($answer !== null && !$history): ?>
      <div class="card mb-2 border-primary"><div class="card-body" style="white-space:pre-wrap"><?= e($answer) ?></div></div>
    <?php endif; ?>

    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="chat">
          <label class="form-label">پیام</label>
          <textarea class="form-control mb-3" name="message" rows="3" maxlength="4000" placeholder="مثلاً: باجی چند محصول دارد؟" required></textarea>
          <button class="btn btn-primary" type="submit">ارسال</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
