<?php
require __DIR__ . '/bootstrap.php';
session_start();

session_destroy(); // これ「だけ」

// destroy 直後に、同一リクエストで認可判定が走ったと仮定して観察する
dump_json([
    'endpoint'          => 'logout_bad',
    'after_session_id'  => session_id(),           // 空文字になる
    'after_session'     => $_SESSION,              // メモリには残る
    'still_admin'       => ($_SESSION['role'] ?? null) === 'admin', // ← trueなら素通り
]);
