<?php
require __DIR__ . '/bootstrap.php';
ini_set('session.use_strict_mode', '1'); // 推奨設定
session_start();
dump_json([
    'endpoint'   => 'me_strict',
    'session_id' => session_id(),
    'session'    => $_SESSION,
]);
