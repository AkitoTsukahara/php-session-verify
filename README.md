# php-session-verify

`session_destroy()` がセッションの3層（リクエスト / サーバー / クライアント）のうち
どれを消し、どれを消し残すのかを、実際のHTTP往復で観察する検証一式です。

記事「`session_destroy()` だけではログアウトにならない」の観察1〜5に、
ファイル番号が対応しています。

**必要なものは Docker だけです。** 観察結果は `session.use_strict_mode` や
`session.save_path` の値で変わるため、この検証一式は「同じ設定・同じPHPで動かす」
ことを前提にしています。ホストのPHPを使うと結果に差分が出るので、
コンテナ内で完結させてください。

## すぐ動かす

```bash
git clone https://github.com/AkitoTsukahara/php-session-verify.git
cd php-session-verify
docker build -t session-verify .
docker run --rm session-verify
```

観察1〜5を番号順に再現し、各ステップのレスポンスヘッダ・ボディ・
セッションファイル・Cookieジャーを表示します。

タイムスタンプ方式（06/07）まで含めて流す場合:

```bash
docker run --rm session-verify bash run.sh --all
```

## テスト

観察1〜5と補足A/B（06/07）は、そのまま PHPUnit のテストにもなっています。
記事の各観察を断定文にしたもので、**3層それぞれ**（レスポンスJSON / `var/` の
セッションファイル / Cookieジャー）を確認します。

```bash
docker run --rm session-verify composer test
```

```
 ✔ 観察1: ログイン直後、リクエスト層・サーバー層・クライアント層が同一IDで一致する
 ✔ 観察2: session_destroy() だけではサーバー層しか消えず、$_SESSION と Cookie は残る
 ✔ 観察3: 残った Cookie で再アクセスすると破棄済みの同じIDが「採用」される (strict_mode=0)
 ✔ 観察4: use_strict_mode=1 で受けると古いIDは拒否され、新IDが発行される
 ✔ 観察5: マニュアル推奨のログアウトは3層すべてを片付ける
 ✔ 補足A: タイムスタンプ方式の再生成では、旧IDが destroyed 付きで残り新IDが採番される
 ✔ 補足B: 検出側は destroyed マークの経過時間で FRESH / 猶予内 / 猶予超過 に分岐する

OK (7 tests, 45 assertions)
```

上の一覧表示は `docker run --rm session-verify vendor/bin/phpunit --testdox` で出ます。

テストは自前でビルトインサーバーを空きポートに立てるので、自動起動している
サーバーや他のコンテナの状態には依存しません。テストコードは `tests/` にあります。
`.github/workflows/test.yml` で、push ごとに同じイメージで PHPUnit と `run.sh --all`
を回す設定も同梱しています。

## 出力の読み方

各レスポンスの末尾に `_env` が付きます。**どの設定で観察した結果なのか**を
毎回自己申告させるためのものです。観察3と観察4は `use_strict_mode` の
0 / 1 で結果が反転するので、設定と結果を同じ画面で確認できるようにしています。

```json
{
  "endpoint": "me",
  "session_id": "aq7bhk...",
  "session": { "user_id": 42, "role": "admin" },
  "is_login": true,
  "_env": { "php": "8.3.33", "use_strict_mode": 0 }
}
```

「サーバー層」として表示されるのは `var/`（`session.save_path`）に置かれた
セッションファイルの中身です。

## 構成

```
session-verify/
├── run.sh                   # 1コマンドで観察1〜5を再現（--all で 06/07 も）
├── show_var.sh              # サーバー層（var/）のセッションファイルを一覧表示
├── Dockerfile               # php:8.3-cli ベース（curl / composer 込み）
├── docker-entrypoint.sh     # コンテナ起動時にビルトインサーバーを自動起動
├── composer.json / .lock    # PHPUnit（テスト実行時のみ使用）
├── phpunit.xml
├── tests/
│   ├── SessionDestroyObservationsTest.php  # 観察1〜5・補足A/B の断定
│   ├── HttpClient.php       # Set-Cookie を解釈する最小ブラウザ
│   └── BuiltinServer.php    # テスト専用サーバーの起動・停止
├── php.recommended.ini      # 記事の「あるべき設定」
└── public/
    ├── bootstrap.php        # 共通設定と dump_json()
    ├── 01_login.php         # 観察1: ログイン（user_id=42 をセッションへ）
    ├── 02_logout_bad.php    # 観察2: NG例 session_destroy() だけ
    ├── 03_me.php            # 観察3: 残ったCookieで再アクセス
    ├── 04_me_strict.php     # 観察4: use_strict_mode=1 で受ける
    ├── 05_logout_good.php   # 観察5: OK例 3層を片付けるログアウト
    ├── 06_regenerate_timestamp.php  # 補足A: タイムスタンプ方式・再生成側
    └── 07_detect_obsolete.php       # 補足B: タイムスタンプ方式・検出側
```

## 手で1本ずつ叩く

コンテナの中にシェルで入ります。**ビルトインサーバーは入った時点で起動済み**なので
（`docker-entrypoint.sh` が立てます）、すぐ curl できます。`var/` も同じ場所から
覗けるので、3層を1画面で追えます。

```bash
docker run --rm -it session-verify bash
```

```
session-verify: ビルトインサーバー起動済み → http://127.0.0.1:8811/  (log: server.log)
root@xxxx:/app#
```

以降はコンテナ内での操作です。

```bash
curl -c jar.txt http://127.0.0.1:8811/01_login.php        # 観察1
./show_var.sh                                             # サーバー層を見る（ID => [中身]）

curl -b jar.txt http://127.0.0.1:8811/02_logout_bad.php   # 観察2
ls var/                                                   # 消えていることを確認

curl -b jar.txt http://127.0.0.1:8811/03_me.php           # 観察3: 古いIDが「採用」される
```

コンテナは `exit` すると消えます。`var/` や `jar.txt` も一緒に消えるので、
毎回まっさらな状態から始まります。

### ブラウザやホストのcurlから叩きたい場合

`serve` を指定するとサーバーとして待ち受け続けます（Ctrl+C で終了）。
ポートを公開し、`var/` をホストにマウントしておくと、セッションファイルを
ホスト側から確認できます。

```bash
docker run --rm -p 8811:8811 -v "$PWD/var:/app/var" session-verify serve
```

```bash
curl -c jar.txt http://127.0.0.1:8811/01_login.php   # ホスト側
cat var/sess_*
```

## タイムスタンプ方式（06/07）を手で試す

06 は「旧セッションを即削除せず `destroyed` マークを付けて新IDへ移る」再生成側、
07 は「`destroyed` マークと猶予期間で分岐する」検出側の最小モデルです。
上と同じくコンテナ内で:

```bash
curl -c jar.txt http://127.0.0.1:8811/01_login.php
curl -b jar.txt -c jar.txt http://127.0.0.1:8811/06_regenerate_timestamp.php
./show_var.sh    # 旧IDのファイルに destroyed|i:... が残っていることを確認
```

07 は `?mark=<秒>` で「N秒前にマークが付いた」状態を作れます（再現性のための
デモ用パラメータで、本番実装には存在しません）。

```bash
curl -b jar.txt -c jar.txt 'http://127.0.0.1:8811/07_detect_obsolete.php'          # FRESH
curl -b jar.txt -c jar.txt 'http://127.0.0.1:8811/07_detect_obsolete.php?mark=2'   # ALLOWED_WITHIN_GRACE
curl -b jar.txt -c jar.txt 'http://127.0.0.1:8811/07_detect_obsolete.php?mark=400' # DENIED
```

いずれも仕組み理解用の最小モデルです。本番実装では、ユーザーごとのアクティブ
セッションをDB等で追跡する設計が前提になります（記事本文参照）。

## セッション設定について

観察3は `session.use_strict_mode = 0`（PHPのデフォルト）を、観察4は `1` を前提に
しています。`public/bootstrap.php` が必要な設定を明示的に固定しているため、
コンテナのPHPに何が設定されていても観察結果は変わりません。

- `session.save_path` → `var/`
- `session.use_strict_mode` → `0`（観察4のみ、ファイル内で `1` に上書き）
- `session.gc_probability` → `0`（観察の途中でGCに消されないように）
- `session.cookie_secure` → `0`（ローカルHTTP検証のため）

### ファイルを編集しながら試す

イメージはビルド時点のファイルを焼き込むため、ファイルを追加・編集したら
`docker build` のやり直しが必要です。試行錯誤中は、プロジェクトをまるごと
マウントすると再ビルドなしでホスト側の編集がそのまま反映されます。

```bash
docker run --rm -it -v "$PWD:/app" session-verify bash
```

シェルスクリプトを追加したときは、ホスト側で `chmod +x` しておいてください
（`COPY` はパーミッションを引き継ぎます）。

マウントした状態でテストを走らせる場合、`vendor/` はホスト側に無いので
最初に一度だけコンテナ内で `composer install` を実行してください
（ホストの `vendor/` に入ります。Git管理外です）。

```bash
docker run --rm -v "$PWD:/app" session-verify composer install
docker run --rm -v "$PWD:/app" session-verify composer test
```

### 推奨設定（php.recommended.ini）で起動する

`php.recommended.ini` は記事「あるべき設定」の実ファイルです。これを効かせる
ときは、`bootstrap.php` のピン留めを `SESSION_VERIFY_PIN=0` で外します。

自動起動するサーバーは既定設定で動くため、`SESSION_VERIFY_NO_SERVER=1` で
自動起動を止め、`php -c` で自分で立てます。

```bash
docker run --rm -it -v "$PWD:/app" \
  -e SESSION_VERIFY_PIN=0 -e SESSION_VERIFY_NO_SERVER=1 session-verify bash
```

```bash
php -c php.recommended.ini -S 127.0.0.1:8811 -t public > server.log 2>&1 &
curl http://127.0.0.1:8811/01_login.php   # _env.use_strict_mode が 1 になる
```

注意: `php.recommended.ini` は本番想定のため `session.cookie_secure = 1`（HTTPS前提）です。
ローカルのHTTP検証ではクッキーが送られず観察が成立しないので、`0` に変更してください
（ファイル内コメント参照）。上のようにマウントしていれば、ホスト側で編集すれば
そのまま反映されます。

## Docker を使わない場合

PHP 8.x と curl があれば `bash run.sh` でも同じことができます。ただし、
PHPのバージョンやビルドオプションによる差分はこちらでは吸収できません
（セッション設定は `bootstrap.php` が固定するので、そちらは揃います）。

```bash
bash run.sh              # 観察1〜5
bash run.sh --all        # ＋ 補足A/B（06/07）
PORT=9000 bash run.sh    # ポートを変える（既定 8811）
```

`--all` と `PORT` は Docker から実行する場合も同じように使えます
（`PORT` は自動起動するサーバーにも効きます）。

```bash
docker run --rm session-verify bash run.sh --all
docker run --rm -e PORT=9000 session-verify
```

## 検証済み環境

- Docker イメージ: `php:8.3-cli`（実測 PHP 8.3.33）
- 記事執筆時の検証: PHP 8.3.6（ビルトインサーバー）
