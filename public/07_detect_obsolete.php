<?php
/**
 * 観察補足B: タイムスタンプ方式 ── 検出側の最小モデル
 *
 * destroyed マークの経過時間で3分岐する。
 *
 *   マークなし          → FRESH                通常アクセス
 *   猶予内（既定5分）    → ALLOWED_WITHIN_GRACE 遅延した正規リクエストとして許容
 *   猶予超過            → DENIED               攻撃の兆候。全セッション無効化＋再認証
 *
 * 猶予超過は「遅延した正規リクエストでは起きにくい」ことを根拠にした判定で、
 * 即削除していたら、この観測点そのものが存在しない。
 *
 * デモ用パラメータ（本番実装には存在しない、再現性のための細工）:
 *   ?mark=<秒>  … 「N秒前に destroyed が付いた」状態を作って分岐を確かめる
 *   ?clear=1    … destroyed マークを外す
 *
 * ※ 素のファイルセッション＋use_strict_mode=1 では、未初期化IDが新規セッション
 *    として作り直されて destroyed を読めない場合がある。この分岐を実務で成立
 *    させるにはアクティブセッションのDB追跡が要る、という話に繋がる。
 */
require __DIR__ . '/bootstrap.php';

const OBSOLETE_GRACE = 300; // 猶予期間（秒）= 5分

session_start();

// --- デモ用の状態づくり ---
if (isset($_GET['mark'])) {
    $_SESSION['destroyed'] = time() - max(0, (int) $_GET['mark']);
}
if (isset($_GET['clear'])) {
    unset($_SESSION['destroyed']);
}

// --- ここからが検出ロジックの本体 ---
$destroyed = $_SESSION['destroyed'] ?? null;

if ($destroyed === null) {
    dump_json([
        'endpoint' => 'detect_obsolete',
        'status'   => 'FRESH',
        'note'     => 'destroyedマークなし → 通常アクセス',
    ]);
    return;
}

$age = time() - $destroyed;

if ($age <= OBSOLETE_GRACE) {
    dump_json([
        'endpoint'    => 'detect_obsolete',
        'status'      => 'ALLOWED_WITHIN_GRACE',
        'destroyed_at'=> $destroyed,
        'age_sec'     => $age,
        'grace_sec'   => OBSOLETE_GRACE,
        'note'        => '猶予内 → 遅延した正規リクエストとして許容',
    ]);
    return;
}

// 猶予超過 = 攻撃の兆候として扱う
deny_and_reauth();

dump_json([
    'endpoint'     => 'detect_obsolete',
    'status'       => 'DENIED',
    'destroyed_at' => $destroyed,
    'age_sec'      => $age,
    'grace_sec'    => OBSOLETE_GRACE,
    'note'         => '猶予超過 → 全セッション無効化・再認証を強制',
], 401);

/**
 * 最小モデルの「全セッション無効化」。
 * 本番では、このユーザーに紐づく全アクティブセッションをストアから
 * 引いて落とす。ここでは目の前の3層を片付けるだけに留める。
 */
function deny_and_reauth(): void
{
    $_SESSION = [];

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

    session_destroy();
}
