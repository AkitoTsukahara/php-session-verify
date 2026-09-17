# =====================================================================
# session-verify 検証環境（php:8.5-cli ベース）
#
# ビルド:       docker build -t session-verify .
# 一括検証:     docker run --rm session-verify
#               → run.sh が観察1〜5を順に再現
# 06/07 も込み: docker run --rm session-verify bash run.sh --all
# 手で叩く:     docker run --rm -it session-verify bash
#               → 入った時点でサーバーが起動済み。すぐ curl できる
# サーバー起動: docker run --rm -p 8811:8811 session-verify serve
#               → ホストの curl / ブラウザから 01〜07 を個別に叩ける
# テスト:       docker run --rm session-verify composer test
#               → 観察1〜5・補足A/B を PHPUnit で断定
#
# サーバーの自動起動は docker-entrypoint.sh が担う。
#
# 記事の検証環境は PHP 8.5.10。
# （実際に動いたバージョンは各レスポンスの _env.php に出ます）。
# =====================================================================
FROM php:8.5-cli

# curl: run.sh が使う / unzip: composer が配布物を展開するのに使う
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# 依存（PHPUnit）は composer.lock で固定。ソースより先に入れてレイヤを分ける
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY . /app

# セッション保存先（bootstrap.php が session.save_path として使う）
RUN mkdir -p /app/var

EXPOSE 8811

# 起動時にビルトインサーバーを立ててからコマンドへ制御を渡す
ENTRYPOINT ["bash", "/app/docker-entrypoint.sh"]

# デフォルトは観察1〜5の一括再現
CMD ["bash", "run.sh"]
