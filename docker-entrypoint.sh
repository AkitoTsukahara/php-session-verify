#!/usr/bin/env bash
# =====================================================================
# session-verify コンテナのエントリポイント
#
# コンテナ起動時にビルトインサーバーを裏で立ち上げてから、指定された
# コマンドに制御を渡す。これにより
#
#   docker run --rm -it session-verify bash   → 入った時点で curl が通る
#   docker run --rm session-verify            → run.sh は起動済みサーバーを使う
#   docker run --rm -p 8811:8811 session-verify serve
#                                             → サーバーとして待ち受け続ける
#
# 0.0.0.0 にバインドするのは -p でホストに公開できるようにするため。
# 公開しなければコンテナ外からは届かない。
#
# 環境変数:
#   PORT=9000                  ポート変更（既定 8811）
#   SESSION_VERIFY_NO_SERVER=1 自動起動しない（php -c で別設定のサーバーを
#                              自分で立てたいときなど）
# =====================================================================
set -u
cd /app

PORT="${PORT:-8811}"
SERVER_PID=""

# PID 1 として動くため、ハンドラを入れておかないと SIGTERM/SIGINT が
# カーネルに無視される（docker stop / Ctrl+C が効かない）。サーバー起動より
# 前に仕掛けておく。
shutdown() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    exit 0
}
trap shutdown INT TERM

if [ "${SESSION_VERIFY_NO_SERVER:-0}" != "1" ]; then
    mkdir -p var
    php -S "0.0.0.0:${PORT}" -t public > server.log 2>&1 &
    SERVER_PID=$!

    for _ in $(seq 1 50); do
        curl -s -o /dev/null "http://127.0.0.1:${PORT}/__ping" && break
        kill -0 "$SERVER_PID" 2>/dev/null || { echo "サーバーが起動しませんでした:" >&2; cat server.log >&2; exit 1; }
        sleep 0.2
    done
    rm -f var/sess_*   # 起動確認のアクセス分を掃除

    # 対話シェルで入ったときだけ案内を出す
    if [ -t 0 ] && [ "${1:-}" != "serve" ]; then
        echo "session-verify: ビルトインサーバー起動済み → http://127.0.0.1:${PORT}/  (log: server.log)"
    fi
fi

case "${1:-}" in
    serve)
        [ -n "$SERVER_PID" ] || { echo "SESSION_VERIFY_NO_SERVER=1 では serve は使えません" >&2; exit 1; }
        echo "session-verify: http://0.0.0.0:${PORT}/ で待ち受け中（Ctrl+C で終了）"
        wait "$SERVER_PID"
        ;;
    *)
        exec "$@"
        ;;
esac
