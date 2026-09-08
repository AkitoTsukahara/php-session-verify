<?php
/**
 * 観察補足A: タイムスタンプ方式 ── 再生成側の最小モデル
 *
 * 旧セッションを即削除せず「destroyed（破棄予定）」の印を刻んで新IDへ移る。
 * 印が残るからこそ、後から古いIDで来たリクエストを 07 側で検出できる。
 *
 * ※ 仕組み理解のための最小モデル。本番実装は、ユーザーごとのアクティブ
 *    セッションをDB等で追跡する前提（PHPマニュアル「セッション管理の基礎」）。
 */
require __DIR__ . '/bootstrap.php';

session_start();
$old_id = session_id();

// (1) 旧セッションに「破棄予定」を刻む。ここでは消さない
$_SESSION['destroyed'] = time();
session_write_close();

// (2) 新IDで開き直す
session_id(session_create_id());
session_start();

// (3) 新セッションを組み立てる。印は持ち込まない
unset($_SESSION['destroyed']);
$_SESSION['user_id'] = 42;

dump_json([
    'endpoint' => 'regenerate_timestamp',
    'old_id'   => $old_id,
    'new_id'   => session_id(),
    'session'  => $_SESSION,
    'hint'     => 'var/ に旧IDのファイルが destroyed 付きで残る',
]);
