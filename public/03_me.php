<?php
require __DIR__ . '/bootstrap.php';
session_start();
dump_json([
    'endpoint'   => 'me',
    'session_id' => session_id(),
    'session'    => $_SESSION,
    'is_login'   => isset($_SESSION['user_id']),
]);
