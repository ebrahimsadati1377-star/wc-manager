<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/OAuthService.php';

header('Cache-Control: no-store');
header('Pragma: no-cache');

$oauth = new WcManagerOAuthService();
$input = array_merge($_GET, $_POST);
$action = trim((string)($_POST['action'] ?? ''));
$errorMessage = '';

try {
    $request = $oauth->validateAuthorizationRequest($input);
} catch (WcManagerOAuthException $e) {
    oauthRenderPage('Authorization request rejected', '<p>' . e($e->getMessage()) . '</p>', 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $errorMessage = 'نشست معتبر نیست. صفحه را دوباره باز کنید.';
    } elseif ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!Auth::attempt($username, $password)) {
            $retry = Auth::loginRetryAfter($username);
            $errorMessage = $retry > 0
                ? 'ورود موقتاً محدود شده است. چند دقیقه دیگر دوباره تلاش کنید.'
                : 'نام کاربری یا رمز عبور صحیح نیست.';
        }
    } elseif ($action === 'deny' && Auth::isAdmin()) {
        logActivity('oauth_authorization_denied', 'user:' . (int)(Auth::user()['id'] ?? 0), 'client=chatgpt');
        oauthRedirect($request['redirect_uri'], [
            'error' => 'access_denied',
            'error_description' => 'The WC Manager administrator denied access.',
            'state' => $request['state'],
            'iss' => WcManagerOAuthService::ISSUER,
        ]);
    } elseif ($action === 'approve' && Auth::isAdmin()) {
        try {
            $user = Auth::user();
            $code = $oauth->createAuthorizationCode((int)($user['id'] ?? 0), $request);
            oauthRedirect($request['redirect_uri'], [
                'code' => $code,
                'state' => $request['state'],
                'iss' => WcManagerOAuthService::ISSUER,
            ]);
        } catch (WcManagerOAuthException $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$hidden = oauthHiddenInputs($request);
if (!Auth::check()) {
    $errorHtml = $errorMessage !== '' ? '<div class="notice error">' . e($errorMessage) . '</div>' : '';
    $body = $errorHtml . '
        <p>برای اتصال ChatGPT به WC Manager ابتدا با حساب مدیر WC Manager وارد شوید.</p>
        <form method="post" autocomplete="on">
          ' . $hidden . '
          <input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">
          <input type="hidden" name="action" value="login">
          <label>نام کاربری<input name="username" autocomplete="username" required></label>
          <label>رمز عبور<input type="password" name="password" autocomplete="current-password" required></label>
          <button type="submit">ورود امن و ادامه</button>
        </form>
        <p class="muted">رمز عبور فقط برای ورود به WC Manager استفاده می‌شود و برای ChatGPT ارسال نمی‌شود.</p>';
    oauthRenderPage('اتصال ChatGPT به WC Manager', $body, 200);
}

if (!Auth::isAdmin()) {
    oauthRenderPage('Access denied', '<p>فقط حساب مدیر فعال WC Manager اجازه اتصال Plugin را دارد.</p>', 403);
}

$labels = WcManagerOAuthService::scopeLabels();
$scopeItems = '';
foreach ($request['scopes'] as $scope) {
    $scopeItems .= '<li><strong>' . e($scope) . '</strong><br><span>' . e($labels[$scope] ?? $scope) . '</span></li>';
}
$errorHtml = $errorMessage !== '' ? '<div class="notice error">' . e($errorMessage) . '</div>' : '';
$user = Auth::user();
$body = $errorHtml . '
    <p>ChatGPT درخواست دسترسی به WC Manager را دارد. حساب متصل: <strong>' . e((string)($user['username'] ?? 'admin')) . '</strong></p>
    <ul class="scopes">' . $scopeItems . '</ul>
    <div class="notice">هیچ Consumer Secret ووکامرس، WordPress App Password یا توکن باسلام به ChatGPT داده نمی‌شود. عملیات نوشتن فقط از طریق ابزارهای مشخص Plugin انجام می‌شود.</div>
    <form method="post" class="actions">
      ' . $hidden . '
      <input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">
      <button class="secondary" type="submit" name="action" value="deny">رد کردن</button>
      <button type="submit" name="action" value="approve">تأیید اتصال</button>
    </form>
    <p class="muted"><a href="../plugin.php?page=privacy">Privacy Policy</a> · <a href="../plugin.php?page=terms">Terms</a></p>';
oauthRenderPage('اجازه دسترسی WC Manager', $body, 200);

function oauthHiddenInputs(array $request): string
{
    $fields = [
        'response_type' => 'code',
        'client_id' => $request['client_id'],
        'redirect_uri' => $request['redirect_uri'],
        'scope' => $request['scope'],
        'state' => $request['state'],
        'resource' => $request['resource'],
        'code_challenge' => $request['code_challenge'],
        'code_challenge_method' => 'S256',
    ];
    $html = '';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e((string)$value) . '">';
    }
    return $html;
}

function oauthRedirect(string $uri, array $params): void
{
    $separator = str_contains($uri, '?') ? '&' : '?';
    header('Location: ' . $uri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 302);
    exit;
}

function oauthRenderPage(string $title, string $body, int $status): void
{
    http_response_code($status);
    ?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f7f9;color:#16181d;margin:0;padding:32px 16px}.card{max-width:620px;margin:5vh auto;background:#fff;border:1px solid #e4e7ec;border-radius:20px;padding:28px;box-shadow:0 14px 40px rgba(16,24,40,.08)}h1{font-size:1.5rem;margin:0 0 16px}p{line-height:1.8}label{display:block;margin:14px 0;font-weight:650}input{display:block;width:100%;box-sizing:border-box;margin-top:7px;padding:12px;border:1px solid #cfd5df;border-radius:10px;font:inherit}button{border:0;border-radius:10px;padding:12px 18px;font:inherit;font-weight:700;background:#111827;color:#fff;cursor:pointer}.secondary{background:#e9edf3;color:#111827}.actions{display:flex;gap:10px;justify-content:flex-start;margin-top:20px}.notice{background:#f1f5f9;border-radius:12px;padding:12px 14px;line-height:1.7}.notice.error{background:#fef2f2;color:#991b1b}.muted{font-size:.9rem;color:#667085}.scopes{padding-right:22px}.scopes li{margin:12px 0;line-height:1.55}.scopes span{color:#475467}a{color:#175cd3}
</style></head><body><main class="card"><h1><?= e($title) ?></h1><?= $body ?></main></body></html><?php
    exit;
}
