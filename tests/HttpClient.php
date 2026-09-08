<?php
declare(strict_types=1);

namespace SessionVerify\Tests;

/**
 * ブラウザの最小モデル。
 *
 * テストで確認したいのは「クライアント層（Cookie）に何が残るか」なので、
 * curl の自動クッキー管理には任せず、Set-Cookie を自分で解釈して
 * ジャー（$cookies）を更新する。削除指示（Max-Age=0 / 過去日時 / deleted）
 * を受け取ったらジャーから消す。
 */
final class HttpClient
{
    /** @var array<string, string> name => value */
    public array $cookies = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function get(string $path): Response
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [];
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('curl: ' . curl_error($ch));
        }
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerBlock = substr($raw, 0, $headerSize);
        $body        = substr($raw, $headerSize);

        $lines = array_values(array_filter(
            array_map('trim', explode("\r\n", $headerBlock)),
            static fn (string $l): bool => $l !== '' && !str_starts_with($l, 'HTTP/')
        ));

        $response = new Response($status, $lines, $body);
        foreach ($response->setCookies() as $sc) {
            $this->applySetCookie($sc);
        }
        return $response;
    }

    /** ブラウザが Set-Cookie を受けたときの挙動を模す */
    private function applySetCookie(string $setCookie): void
    {
        $parts = array_map('trim', explode(';', $setCookie));
        [$name, $value] = array_pad(explode('=', array_shift($parts), 2), 2, '');

        $delete = $value === 'deleted';
        foreach ($parts as $attr) {
            [$k, $v] = array_pad(explode('=', $attr, 2), 2, '');
            $k = strtolower($k);
            if ($k === 'max-age' && (int) $v <= 0) {
                $delete = true;
            }
            if ($k === 'expires' && strtotime($v) !== false && strtotime($v) < time()) {
                $delete = true;
            }
        }

        if ($delete) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }
}

final class Response
{
    /** @param list<string> $headers "Name: value" 形式の生ヘッダ行 */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON body expected, got: ' . $this->body);
        }
        return $decoded;
    }

    /** @return list<string> Set-Cookie ヘッダの値だけ */
    public function setCookies(): array
    {
        $out = [];
        foreach ($this->headers as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $out[] = trim(substr($line, strlen('Set-Cookie:')));
            }
        }
        return $out;
    }

    public function hasSetCookie(): bool
    {
        return $this->setCookies() !== [];
    }
}
