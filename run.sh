#!/usr/bin/env bash
# =====================================================================
# session-verify: session_destroy() 3層検証ランナー
#
# 記事「session_destroy() だけではログアウトにならない」の
# 観察1〜5を、番号どおりの順番で一括再現します。
#
#   観察1  01_login.php        ログイン。3層が1つのIDで紐づいていることを確認
#   観察2  02_logout_bad.php   NG例: destroyだけ。$_SESSION と Cookie が残る
#   観察3  03_me.php           残ったCookieで再アクセス。同じIDが「採用」される
#   観察4  04_me_strict.php    strict_mode=1。古いIDが拒否され新IDを発行
#   観察5  05_logout_good.php  OK例: 3層を片付けるログアウト
#
# 必要なもの: PHP 8.x / curl
#
# 通常は Docker 経由で実行します（README 参照）。環境差分が出ないためです。
#   docker run --rm session-verify
#   docker run --rm session-verify bash run.sh --all
#
# 直接実行する場合:
#   bash run.sh              観察1〜5
#   bash run.sh --all        観察1〜5 ＋ 補足A/B（06/07 タイムスタンプ方式）
#   PORT=9000 bash run.sh    ポートを変える（既定 8811）
#
# セッション設定は public/bootstrap.php が固定するため、実行するマシンの
# php.ini に結果は左右されません（use_strict_mode=0 前提を含む）。
# =====================================================================
set -u

cd "$(dirname "$0")"

PORT="${PORT:-8811}"
B="http://127.0.0.1:${PORT}"
WITH_EXTRA=0

for arg in "$@"; do
    case "$arg" in
        --all) WITH_EXTRA=1 ;;
        -h|--help) sed -n '2,26p' "$0"; exit 0 ;;
        *) echo "不明なオプション: $arg （--all / --help）" >&2; exit 2 ;;
    esac
done

# ---------------------------------------------------------------
# 前提チェック
# ---------------------------------------------------------------
for cmd in php curl; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "エラー: '$cmd' が見つかりません。" >&2
        echo "  ローカルにPHPが無い場合は Docker で実行できます:" >&2
        echo "    docker build -t session-verify . && docker run --rm session-verify" >&2
        exit 1
    }
done

TMP=$(mktemp -d)
cleanup() {
    [ -n "${PID:-}" ] && kill "$PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

mkdir -p var
rm -f var/sess_*

# ---------------------------------------------------------------
# ビルトインサーバー
#   Docker コンテナ内では docker-entrypoint.sh が既に起動しているので
#   それを使う。無ければ自分で起こす（ローカル実行時）。
# ---------------------------------------------------------------
PID=""
if curl -s -o /dev/null "$B/__ping"; then
    SERVER_NOTE="起動済みのサーバーを使用"
else
    php -S "127.0.0.1:${PORT}" -t public > server.log 2>&1 &
    PID=$!
    for _ in $(seq 1 50); do
        if curl -s -o /dev/null "$B/__ping"; then break; fi
        kill -0 "$PID" 2>/dev/null || { echo "サーバーが起動しませんでした:" >&2; cat server.log >&2; exit 1; }
        sleep 0.2
    done
    curl -s -o /dev/null "$B/__ping" || {
        echo "エラー: ${B} に接続できません（ポート ${PORT} が使用中かもしれません）。" >&2
        echo "  別のポートで: PORT=9000 bash run.sh" >&2
        cat server.log >&2
        exit 1
    }
    SERVER_NOTE="このスクリプトが起動"
fi
rm -f var/sess_*   # 起動確認のアクセス分を掃除

echo "PHP $(php -r 'echo PHP_VERSION;') / ${B} （ドキュメントルート: public/、${SERVER_NOTE}）"

line(){ printf '\n═══ %s ═══\n' "$1"; }
server_layer(){
    local found=0 f
    for f in var/sess_*; do
        [ -e "$f" ] && { echo "  $(basename "$f") => [$(cat "$f")]"; found=1; }
    done
    if [ $found -eq 0 ]; then echo "  (セッションファイルなし)"; fi
}
sid_of(){ awk '/PHPSESSID/{print $7}' "$1"; }

# ---------------------------------------------------------------
line "観察1: ログイン ── 3層が同一IDで一致する"
J="$TMP/jar.txt"
curl -s -c "$J" $B/01_login.php | sed 's/^/  /'
echo "-- サーバー層 --"; server_layer
echo "-- クライアント層 --"; echo "  PHPSESSID = $(sid_of "$J")"
OLD=$(sid_of "$J")

# ---------------------------------------------------------------
line "観察2: session_destroy() だけ ── サーバー層しか消えない"
echo "-- レスポンスヘッダ（Set-Cookie の有無に注目）--"
curl -s -D - -o "$TMP/body.txt" -b "$J" $B/02_logout_bad.php | grep -iE 'set-cookie' \
    || echo "  (Set-Cookie なし = クッキー削除の指示が出ていない)"
echo "-- レスポンスボディ（still_admin に注目）--"; sed 's/^/  /' "$TMP/body.txt"
echo "-- サーバー層 --"; server_layer
echo "-- クライアント層 --"; echo "  PHPSESSID = $(sid_of "$J") ← 残ったまま"

# ---------------------------------------------------------------
line "観察3: 残ったCookieで再アクセス ── 同じIDが「採用」される (strict=0)"
curl -s -b "$J" $B/03_me.php | sed 's/^/  /'
echo "-- サーバー層 --"; server_layer
NEW=$(sid_of "$J")
echo "  旧ID=$OLD"
echo "  現ID=$NEW → $([ "$OLD" = "$NEW" ] && echo '同一。破棄したはずのIDが復活（採用）' || echo '別物')"

# ---------------------------------------------------------------
line "観察4: 同じ状況を strict_mode=1 で受ける ── 古いIDは拒否される"
J2="$TMP/jar2.txt"; rm -f var/sess_*
curl -s -c "$J2" $B/01_login.php >/dev/null
O2=$(sid_of "$J2")
curl -s -b "$J2" $B/02_logout_bad.php >/dev/null
echo "-- レスポンスヘッダ（新IDの Set-Cookie が出る）--"
curl -s -D - -o /dev/null -b "$J2" -c "$J2" $B/04_me_strict.php | grep -iE 'set-cookie' | sed 's/^/  /'
N2=$(sid_of "$J2")
echo "  旧ID=$O2"
echo "  新ID=$N2 → $([ "$O2" = "$N2" ] && echo '採用された' || echo '拒否され、新IDが発行された')"

# ---------------------------------------------------------------
line "観察5: マニュアル推奨の3層ログアウト ── すべて片付く"
J3="$TMP/jar3.txt"; rm -f var/sess_*
curl -s -c "$J3" $B/01_login.php >/dev/null
echo "-- レスポンスヘッダ（過去日時の Set-Cookie が出る）--"
curl -s -D - -o /dev/null -b "$J3" -c "$J3" $B/05_logout_good.php | grep -iE 'set-cookie' | sed 's/^/  /'
echo "-- サーバー層 --"; server_layer
echo "-- クライアント層 --"; grep -q PHPSESSID "$J3" && echo "  (残存)" || echo "  (セッションクッキーは削除された)"

if [ "$WITH_EXTRA" -eq 0 ]; then
    echo
    echo "観察1〜5 完了。"
    echo "06/07（タイムスタンプ方式）も見る場合: bash run.sh --all"
    exit 0
fi

# ---------------------------------------------------------------
line "補足A: タイムスタンプ方式・再生成側 ── 旧IDは印つきで残る"
J4="$TMP/jar4.txt"; rm -f var/sess_*
curl -s -c "$J4" $B/01_login.php >/dev/null
curl -s -b "$J4" -c "$J4" $B/06_regenerate_timestamp.php | sed 's/^/  /'
echo "-- サーバー層（旧IDに destroyed が残る）--"; server_layer

# ---------------------------------------------------------------
line "補足B: タイムスタンプ方式・検出側 ── 猶予で3分岐する"
J5="$TMP/jar5.txt"; rm -f var/sess_*
curl -s -c "$J5" $B/01_login.php >/dev/null

echo "-- [FRESH] destroyedマークなし --"
curl -s -b "$J5" -c "$J5" "$B/07_detect_obsolete.php" | sed 's/^/  /'
echo "-- [WITHIN] destroyed 2秒前（猶予5分内）--"
curl -s -b "$J5" -c "$J5" "$B/07_detect_obsolete.php?mark=2" | sed 's/^/  /'
echo "-- [BEYOND] destroyed 400秒前（猶予超過）--"
curl -s -b "$J5" -c "$J5" "$B/07_detect_obsolete.php?mark=400" | sed 's/^/  /'
echo "-- サーバー層（DENIEDで3層とも片付いた）--"; server_layer

echo
echo "観察1〜5 ＋ 補足A/B 完了。"
