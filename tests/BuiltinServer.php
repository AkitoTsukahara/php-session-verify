<?php
declare(strict_types=1);

namespace SessionVerify\Tests;

/**
 * テスト専用のビルトインサーバー。
 *
 * docker-entrypoint.sh が立てるサーバーとは独立に、空きポートで自分の分を
 * 起こす。テストが他の何かの状態に依存しないようにするため。
 */
final class BuiltinServer
{
    /** @var resource|null */
    private $proc = null;
    public readonly string $baseUrl;

    public function __construct(private readonly string $docroot)
    {
        $this->baseUrl = 'http://127.0.0.1:' . self::freePort();
    }

    public function start(): void
    {
        $host = substr($this->baseUrl, strlen('http://'));
        $log  = sys_get_temp_dir() . '/session-verify-test-server.log';
        $cmd  = [PHP_BINARY, '-S', $host, '-t', $this->docroot];

        $this->proc = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes);
        if (!is_resource($this->proc)) {
            throw new \RuntimeException('ビルトインサーバーを起動できません');
        }

        // 起動完了までポーリング（最大 5 秒）
        for ($i = 0; $i < 50; $i++) {
            $ch = curl_init($this->baseUrl . '/__ping');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
            $ok = curl_exec($ch) !== false;
            curl_close($ch);
            if ($ok) {
                return;
            }
            usleep(100_000);
        }
        $this->stop();
        throw new \RuntimeException("サーバーが応答しません: {$this->baseUrl}\n" . @file_get_contents($log));
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("空きポートを取得できません: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
