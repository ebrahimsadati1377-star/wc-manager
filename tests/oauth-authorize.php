<?php
// Isolate the real entrypoint from production credentials and database.
$root = sys_get_temp_dir() . '/wcm-oauth-' . bin2hex(random_bytes(8));
mkdir($root . '/public/oauth', 0700, true);
mkdir($root . '/includes', 0700, true);
copy(__DIR__ . '/../public/oauth/authorize.php', $root . '/public/oauth/authorize.php');
file_put_contents($root . '/includes/OAuthService.php', '<?php');
file_put_contents($root . '/run.php', '<?php register_shutdown_function(function () { fwrite(STDERR, "STATUS=" . http_response_code()); }); require __DIR__ . "/public/oauth/authorize.php";');
try {
    foreach ([
        ["<?php throw new RuntimeException('private-database-detail');", 500, 'خطای اتصال'],
        ['<?php function e($s) { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); } class WcManagerOAuthException extends RuntimeException {} class WcManagerOAuthService { function validateAuthorizationRequest($i) { throw new WcManagerOAuthException("Only response_type=code is supported."); } }', 400, 'Authorization request rejected'],
    ] as [$bootstrap, $status, $text]) {
        file_put_contents($root . '/includes/bootstrap.php', $bootstrap);
        $process = proc_open([PHP_BINARY, $root . '/run.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !str_contains($body, $text) || !str_contains($body, '</html>') || str_contains($body, 'private-database-detail') || !str_contains($errors, 'STATUS=' . $status)) {
            throw new RuntimeException('OAuth response regression, expected HTTP ' . $status);
        }
    }
    echo "OAuth bootstrap failure and invalid request tests passed.\n";
} finally {
    foreach (['public/oauth/authorize.php', 'includes/bootstrap.php', 'includes/OAuthService.php', 'run.php'] as $file) { unlink($root . '/' . $file); }
    rmdir($root . '/public/oauth'); rmdir($root . '/public'); rmdir($root . '/includes'); rmdir($root);
}
