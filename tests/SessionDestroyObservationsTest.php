<?php
declare(strict_types=1);

namespace SessionVerify\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 記事の観察1〜5と補足A/B（06/07）を、そのまま断定文にしたテスト。
 *
 * 各テストは「3層」を個別に確認する:
 *   リクエスト層  … レスポンスJSON（$_SESSION の中身）
 *   サーバー層    … var/ のセッションファイル
 *   クライアント層 … HttpClient が保持する Cookie ジャー
 */
final class SessionDestroyObservationsTest extends TestCase
{
    private static BuiltinServer $server;
    private static string $varDir;
    private HttpClient $client;

    public static function setUpBeforeClass(): void
    {
        $root         = dirname(__DIR__);
        self::$varDir = $root . '/var';
        self::$server = new BuiltinServer($root . '/' . getenv('SESSION_VERIFY_TEST_DOCROOT'));
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::clearServerLayer();
        $this->client = new HttpClient(self::$server->baseUrl);
    }

    // ------------------------------------------------------------------
    // 観察1〜5
    // ------------------------------------------------------------------

    #[Test]
    #[TestDox('観察1: ログイン直後、リクエスト層・サーバー層・クライアント層が同一IDで一致する')]
    public function login_aligns_three_layers_on_same_id(): void
    {
        $json = $this->login();
        $id   = $json['session_id'];

        // リクエスト層
        $this->assertNotSame('', $id);
        $this->assertSame(['user_id' => 42, 'role' => 'admin'], $json['session']);

        // サーバー層
        $files = self::serverLayer();
        $this->assertArrayHasKey($id, $files, 'sess_<id> のファイルが作られている');
        $this->assertSame('user_id|i:42;role|s:5:"admin";', $files[$id]);

        // クライアント層
        $this->assertSame($id, $this->client->cookies['PHPSESSID'] ?? null);

        // 前提: use_strict_mode=0（PHPのデフォルト）で観察している
        $this->assertSame(0, $json['_env']['use_strict_mode']);
    }

    #[Test]
    #[TestDox('観察2: session_destroy() だけではサーバー層しか消えず、$_SESSION と Cookie は残る')]
    public function destroy_only_clears_the_server_layer(): void
    {
        $id  = $this->login()['session_id'];
        $res = $this->client->get('/02_logout_bad.php');
        $json = $res->json();

        // クライアント層: 削除指示が出ていない → Cookie は残ったまま
        $this->assertFalse($res->hasSetCookie(), 'Set-Cookie が出ない（クッキー削除の指示が無い）');
        $this->assertSame($id, $this->client->cookies['PHPSESSID'] ?? null, 'Cookie は残ったまま');

        // リクエスト層: ID は空になるが $_SESSION はメモリに残る
        $this->assertSame('', $json['after_session_id']);
        $this->assertSame(['user_id' => 42, 'role' => 'admin'], $json['after_session']);
        $this->assertTrue($json['still_admin'], 'destroy 直後の認可判定が素通りする');

        // サーバー層: ここだけ消えている
        $this->assertSame([], self::serverLayer());
    }

    #[Test]
    #[TestDox('観察3: 残った Cookie で再アクセスすると破棄済みの同じIDが「採用」される (strict_mode=0)')]
    public function stale_cookie_id_is_adopted_when_strict_mode_is_off(): void
    {
        $old = $this->login()['session_id'];
        $this->client->get('/02_logout_bad.php');

        $json = $this->client->get('/03_me.php')->json();

        $this->assertSame($old, $json['session_id'], '破棄したはずのIDがそのまま採用される');
        $this->assertSame([], $json['session']);
        $this->assertFalse($json['is_login']);

        $files = self::serverLayer();
        $this->assertSame([$old => ''], $files, '同じIDで空のセッションファイルが再生成される');
    }

    #[Test]
    #[TestDox('観察4: use_strict_mode=1 で受けると古いIDは拒否され、新IDが発行される')]
    public function stale_cookie_id_is_rejected_when_strict_mode_is_on(): void
    {
        $old = $this->login()['session_id'];
        $this->client->get('/02_logout_bad.php');

        $res  = $this->client->get('/04_me_strict.php');
        $json = $res->json();

        $this->assertSame(1, $json['_env']['use_strict_mode']);
        $this->assertTrue($res->hasSetCookie(), '新IDの Set-Cookie が出る');
        $this->assertNotSame($old, $json['session_id'], '古いIDは採用されない');
        $this->assertSame($json['session_id'], $this->client->cookies['PHPSESSID'], 'ジャーが新IDに置き換わる');
        $this->assertArrayNotHasKey($old, self::serverLayer(), '古いIDのファイルは作られない');
    }

    #[Test]
    #[TestDox('観察5: マニュアル推奨のログアウトは3層すべてを片付ける')]
    public function recommended_logout_clears_all_three_layers(): void
    {
        $this->login();
        $res  = $this->client->get('/05_logout_good.php');
        $json = $res->json();

        // クライアント層: 過去日時の Set-Cookie で削除される
        $setCookies = $res->setCookies();
        $this->assertCount(1, $setCookies);
        $this->assertStringStartsWith('PHPSESSID=deleted;', $setCookies[0]);
        $this->assertStringContainsString('Max-Age=0', $setCookies[0]);
        $this->assertArrayNotHasKey('PHPSESSID', $this->client->cookies, 'ジャーから消える');

        // リクエスト層
        $this->assertSame([], $json['after_session']);

        // サーバー層
        $this->assertSame([], self::serverLayer());
    }

    // ------------------------------------------------------------------
    // 補足A/B: タイムスタンプ方式（最小モデル）
    // ------------------------------------------------------------------

    #[Test]
    #[TestDox('補足A: タイムスタンプ方式の再生成では、旧IDが destroyed 付きで残り新IDが採番される')]
    public function timestamp_regeneration_keeps_old_session_marked_destroyed(): void
    {
        $old  = $this->login()['session_id'];
        $json = $this->client->get('/06_regenerate_timestamp.php')->json();
        $new  = $json['new_id'];

        $this->assertSame($old, $json['old_id']);
        $this->assertNotSame($old, $new);
        $this->assertSame($new, $this->client->cookies['PHPSESSID'], 'Cookie は新IDに切り替わる');

        $files = self::serverLayer();
        $this->assertCount(2, $files, '旧・新の2ファイルが残る');
        $this->assertMatchesRegularExpression(
            '/^user_id\|i:42;role\|s:5:"admin";destroyed\|i:\d+;$/',
            $files[$old],
            '旧IDは即削除されず destroyed マーク付きで残る'
        );
        $this->assertSame('user_id|i:42;', $files[$new], '新IDには印を持ち込まない');
    }

    #[Test]
    #[TestDox('補足B: 検出側は destroyed マークの経過時間で FRESH / 猶予内 / 猶予超過 に分岐する')]
    public function obsolete_detection_branches_on_grace_period(): void
    {
        $id = $this->login()['session_id'];

        // [FRESH] マークなし
        $res = $this->client->get('/07_detect_obsolete.php');
        $this->assertSame(200, $res->status);
        $this->assertSame('FRESH', $res->json()['status']);

        // [WITHIN] 2秒前 → 猶予内
        $res  = $this->client->get('/07_detect_obsolete.php?mark=2');
        $json = $res->json();
        $this->assertSame(200, $res->status);
        $this->assertSame('ALLOWED_WITHIN_GRACE', $json['status']);
        $this->assertLessThanOrEqual($json['grace_sec'], $json['age_sec']);
        $this->assertArrayHasKey($id, self::serverLayer(), '猶予内ならセッションは維持される');

        // [BEYOND] 400秒前 → 猶予超過 = 全セッション無効化
        $res  = $this->client->get('/07_detect_obsolete.php?mark=400');
        $json = $res->json();
        $this->assertSame(401, $res->status);
        $this->assertSame('DENIED', $json['status']);
        $this->assertGreaterThan($json['grace_sec'], $json['age_sec']);
        $this->assertSame([], self::serverLayer(), 'サーバー層が消える');
        $this->assertArrayNotHasKey('PHPSESSID', $this->client->cookies, 'クライアント層も消える');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function login(): array
    {
        return $this->client->get('/01_login.php')->json();
    }

    /**
     * サーバー層のスナップショット。
     *
     * @return array<string, string> session id => ファイル内容
     */
    private static function serverLayer(): array
    {
        $out = [];
        foreach (glob(self::$varDir . '/sess_*') ?: [] as $file) {
            $out[substr(basename($file), strlen('sess_'))] = (string) file_get_contents($file);
        }
        ksort($out);
        return $out;
    }

    private static function clearServerLayer(): void
    {
        if (!is_dir(self::$varDir)) {
            mkdir(self::$varDir, 0777, true);
        }
        foreach (glob(self::$varDir . '/sess_*') ?: [] as $file) {
            unlink($file);
        }
    }
}
