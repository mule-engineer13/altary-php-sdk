<?php
namespace Altary\Internal;

/**
 * Altary PHP SDK ― クライアントクラス
 *  - エラーログを Altary エンドポイントへ POST 送信
 *  - WordPress 版と同じペイロード構造（timestamp, level_name など）
 */
class Client
{
    private string $apiKey;
    private string $endpoint;

    /** @var array<array<string,mixed>> $queue */
    private array $queue = [];

    public function __construct(string $apiKey, string $endpoint = 'https://api.altary.io/collect')
    {
        $this->apiKey   = $apiKey;
        $this->endpoint = rtrim($endpoint, '/');
    }

    /**
     * .env / 環境変数 / 定数 から自動取得
     * ALTARY_API_KEY が見つからなければ例外を投げる
     */
    public static function fromEnv(string $endpoint = 'https://api.altary.io/collect'): self
    {
        $apiKey = getenv('ALTARY_API_KEY')
            ?: ($_ENV['ALTARY_API_KEY'] ?? null)
            ?: (defined('ALTARY_API_KEY') ? ALTARY_API_KEY : null);

        if (!$apiKey) {
            throw new \RuntimeException('Altary APIキーが設定されていません');
        }
        return new self($apiKey, $endpoint);
    }

    /**
     * ログを即時送信 or キューに追加して flush() でまとめ送信
     * テールコール用途にも対応するため、ここではキューへ push
     */
    public function send(array $data): void
    {
        // 統一フォーマットを付与
        $data['timestamp'] = time() + 9 * 3600; // JST

        if (isset($data['errno'])) {
            $data['level_name'] = $this->mapErrorLevel($data['errno']);
        }

        $data['ip'] = $this->getClientIp();

        $uaInfo              = $this->parseUserAgentFull($_SERVER['HTTP_USER_AGENT'] ?? '');
        $data['os']      = $uaInfo['os'];
        $data['browser'] = $uaInfo['browser'];
        $data['device']  = $uaInfo['device'];

        if (isset($data['file'], $data['line']) && is_readable($data['file'])) {
            $data['file_excerpt'] = $this->getFileExcerpt($data['file'], (int)$data['line']);
        }

        $this->queue[] = $data;
    }

    /**
     * 終了時または手動呼び出しでバッチ送信
     */
    public function flush(): void
    {
        foreach ($this->queue as $payload) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ];

            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // 簡易ログ（必要に応じて外部 Logger に置き換え）
            if ($curlError || $httpCode >= 400) {
                error_log(sprintf('[Altary] Send failed HTTP=%d Error=%s Response=%s', $httpCode, $curlError, $response));
            }
        }
        // 送信済みキューをクリア
        $this->queue = [];
    }

    /* ---------------------------------------------------------------------
     | 内部ユーティリティ
     |-------------------------------------------------------------------- */

    private function mapErrorLevel(int $errno): string
    {
        return match ($errno) {
            E_ERROR, E_USER_ERROR               => 'error',
            E_WARNING, E_USER_WARNING           => 'warning',
            E_NOTICE,  E_USER_NOTICE            => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED     => 'deprecated',
            default                             => 'info',
        };
    }

    private function getClientIp(): string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return explode(',', $_SERVER[$key])[0];
            }
        }
        return '0.0.0.0';
    }

    /**
     * 非常に簡易な UA 解析（必要なら外部ライブラリに差し替え可）
     */
    private function parseUserAgentFull(string $ua): array
    {
        $os = 'Unknown OS';
        $browser = 'Unknown';
        $device = 'PC';

        if (preg_match('/Windows NT/i', $ua))         $os = 'Windows';
        elseif (preg_match('/Mac OS X/i', $ua))       $os = 'macOS';
        elseif (preg_match('/Linux/i', $ua))          $os = 'Linux';
        elseif (preg_match('/Android/i', $ua))        $os = 'Android';
        elseif (preg_match('/iPhone|iPad/i', $ua))    $os = 'iOS';

        if (preg_match('/Chrome\/([\d.]+)/i',  $ua, $m))      $browser = 'Chrome ' . $m[1];
        elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m))  $browser = 'Firefox ' . $m[1];
        elseif (preg_match('/Safari\/([\d.]+)/i',  $ua) && !str_contains($ua, 'Chrome')) $browser = 'Safari';

        if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) $device = 'Mobile';

        return compact('os', 'browser', 'device');
    }

    /**
     * エラー行を中心に前後 $context 行ずつ抜粋
     */
    private function getFileExcerpt(string $file, int $line, int $context = 5): string
    {
        if (!is_readable($file)) {
            return '';
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        $start = max($line - $context - 1, 0);
        $slice = array_slice($lines, $start, $context * 2 + 1, true);
        return implode("\n", array_map(static fn($n, $l) => sprintf('%5d| %s', $n + 1, $l), array_keys($slice), $slice));
    }
}