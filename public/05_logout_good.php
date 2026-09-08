<?php
require __DIR__ . '/bootstrap.php';
session_start();

// (1) リクエスト層
$_SESSION = [];

// (2) クライアント層（PHP 7.3+ のオプション配列でSameSiteも継承）
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?: 'Lax',
    ]);
}

// (3) サーバー層
session_destroy();

dump_json(['endpoint' => 'logout_good', 'after_session' => $_SESSION]);
