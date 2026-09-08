<?php
require __DIR__ . '/bootstrap.php';
session_start();
$_SESSION['user_id'] = 42;
$_SESSION['role']    = 'admin';
dump_json([
    'endpoint'   => 'login',
    'session_id' => session_id(),
    'session'    => $_SESSION,
]);
