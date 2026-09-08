<?php
/**
 * session-verify 共通ブートストラップ
 *
 * 各エンドポイント（01〜07）が最初に読み込む。役割は2つ。
 *
 *   1. 誰の環境で動かしても同じ結果になるようにセッション設定を固定する
 *   2. 観察結果を読みやすく出す dump_json() を提供する
 *
 * 「実行するマシンの php.ini に結果が左右されない」ことが、この検証一式では
 * 記事の再現性そのものなので、設定はここで明示的に上書きしている。
 */
declare(strict_types=1);

/* ------------------------------------------------------------------
 * 土台の設定（常に固定）
 *   セッションの保存先をプロジェクト直下の var/ に向ける。run.sh が
 *   「サーバー層」として覗くのはこのディレクトリ。
 * ---------------------------------------------------------------- */
$savePath = dirname(__DIR__) . '/var';
if (!is_dir($savePath) && !mkdir($savePath, 0777, true) && !is_dir($savePath)) {
    http_response_code(500);
    exit("セッション保存先を作成できません: {$savePath}\n");
}

ini_set('session.save_path', $savePath);
ini_set('session.name', 'PHPSESSID');
ini_set('session.serialize_handler', 'php'); // 記事中のファイル内容表記に合わせる

// GCの抽選を止める。観察の途中で旧セッションが偶然消えると、
// 特に 06/07（タイムスタンプ方式）の再現性が崩れるため。
ini_set('session.gc_probability', '0');
ini_set('session.gc_divisor', '1000');
ini_set('session.gc_maxlifetime', '1440');

/* ------------------------------------------------------------------
 * 記事の前提を再現するためのピン留め
 *
 *   観察1〜3・5 は「session.use_strict_mode = 0（PHPのデフォルト）」を
 *   前提にしている。ホスト側の php.ini が 1 になっていると観察3が再現
 *   しないので、ここで 0 に固定する。観察4（04_me_strict.php）は自分で
 *   1 に上書きする。
 *
 *   php.recommended.ini を効かせて試したいときは、このピン留めを
 *   環境変数で無効化する:
 *
 *     SESSION_VERIFY_PIN=0 php -c php.recommended.ini -S 127.0.0.1:8811 -t public
 * ---------------------------------------------------------------- */
if (getenv('SESSION_VERIFY_PIN') !== '0') {
    ini_set('session.use_strict_mode', '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '0'); // ローカルHTTP検証のため 0
    ini_set('session.cookie_samesite', 'Lax');
}

/**
 * 観察結果をJSONで返す。
 *
 * 末尾の _env は「どの設定で観察したのか」を毎回自己申告させるためのもの。
 * use_strict_mode が 0 か 1 かで観察3・4の意味が反転するので、
 * 結果と設定を同じ画面で確認できるようにしている。
 */
function dump_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $data['_env'] = [
        'php'             => PHP_VERSION,
        'use_strict_mode' => (int) ini_get('session.use_strict_mode'),
    ];

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ), "\n";
}
