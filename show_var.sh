#!/usr/bin/env bash
# サーバー層（var/）のセッションファイルを「ID => [中身]  (バイト数)」形式で表示する。
# 空セッションは「=> []  (0 bytes)」と出るので、ファイルの存在と中身の有無を同時に確認できる。
cd "$(dirname "$0")"
found=0
for f in var/sess_*; do
    [ -e "$f" ] || continue
    printf '%s => [%s]  (%s bytes)\n' "$(basename "$f")" "$(cat "$f")" "$(wc -c < "$f")"
    found=1
done
if [ $found -eq 0 ]; then echo "(セッションファイルなし)"; fi
