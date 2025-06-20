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

    /** @var callable|null $preSendCallback */
    private $preSendCallback = null;

    public function __construct(string $apiKey, string $endpoint = 'https://altary.web-ts.dev/cards/errors')
    {
        $this->apiKey   = $apiKey;
        $this->endpoint = rtrim($endpoint, '/');
    }

    /**
     * .env / 環境変数 / 定数 から自動取得
     * ALTARY_API_KEY が見つからなければ例外を投げる
     */
    public static function fromEnv(string $endpoint = 'https://altary.web-ts.dev/cards/errors'): self
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
     * preSend コールバックを設定
     * エラー送信前にデータをフィルタリング・変更できる
     * 
     * @param callable $callback function(array $data, array $hint): array|null
     */
    public function setPreSendCallback(callable $callback): void
    {
        $this->preSendCallback = $callback;
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

				error_log('[send]');

       	$data['ip'] = $this->getClientIp();
				$data['client_id'] = $this->ensureAltarySession();

        $uaInfo = $this->parseUserAgentFull($_SERVER['HTTP_USER_AGENT'] ?? '');
        $data['os']      = $uaInfo['os'];
        $data['browser'] = $uaInfo['browser'];
        $data['device']  = $uaInfo['device'];

        if (isset($data['file'], $data['line']) && is_readable($data['file'])) {
            $data['file_excerpt'] = $this->getFileExcerpt($data['file'], (int)$data['line']);
        }

        // preSend コールバックが設定されている場合は実行
        if ($this->preSendCallback !== null) {
            $hint = [
                'originalException' => $data['originalException'] ?? null,
                'type' => $data['type'] ?? null,
            ];
            
            $result = call_user_func($this->preSendCallback, $data, $hint);
            
            // null が返された場合は送信をスキップ
            if ($result === null) {
                return;
            }
            
            // 配列が返された場合はデータを更新
            if (is_array($result)) {
                $data = $result;
            }
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
				$osVer = '';
				$browser = 'Unknown Browser';
				$browserVer = '';
				$device = 'Unknown Device';

				// --- OS + version ---
				if (preg_match('/Windows NT ([0-9.]+)/', $ua, $m)) {
						$os = 'Windows';
						$osVer = $m[1];
				} elseif (preg_match('/Mac OS X ([0-9_]+)/', $ua, $m)) {
						$os = 'macOS';
						$osVer = str_replace('_', '.', $m[1]);
				} elseif (preg_match('/Android ([0-9.]+)/', $ua, $m)) {
						$os = 'Android';
						$osVer = $m[1];
				} elseif (preg_match('/iPhone OS ([0-9_]+)/', $ua, $m)) {
						$os = 'iOS';
						$osVer = str_replace('_', '.', $m[1]);
				}

				// --- Browser + version ---
				if (preg_match('/Chrome\/([0-9.]+)/', $ua, $m)) {
						$browser = 'Chrome';
						$browserVer = $m[1];
				} elseif (preg_match('/Firefox\/([0-9.]+)/', $ua, $m)) {
						$browser = 'Firefox';
						$browserVer = $m[1];
				} elseif (
						preg_match('/Version\/([0-9.]+).*Safari/', $ua, $m)
				) {
						$browser = 'Safari';
						$browserVer = $m[1];
				}

				// --- Device ---
				if (stripos($ua, 'iPhone') !== false) {
						$device = 'iPhone';
				} elseif (stripos($ua, 'iPad') !== false) {
						$device = 'iPad';
				} elseif (stripos($ua, 'Pixel') !== false) {
						$device = 'Pixel';
				} elseif (stripos($ua, 'SM-') !== false) {
						$device = 'Samsung Galaxy';
				} elseif (stripos($ua, 'Windows') !== false || stripos($ua, 'Macintosh') !== false) {
						$device = 'PC';
				} elseif (stripos($ua, 'Android') !== false) {
						$device = 'Android Device';
				}

				return [
						'os'      => $os . ($osVer ? ' ' . $osVer : ''),
						'browser' => $browser . ($browserVer ? ' ' . $browserVer : ''),
						'device'  => $device,
				];
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

		private function ensureAltarySession(): string {
				$cookieName = 'altary_session';

				if (isset($_COOKIE[$cookieName]) && preg_match('/^[0-9a-f\-]{36}$/i', $_COOKIE[$cookieName])) {
						return $_COOKIE[$cookieName]; // 既存クッキー
				}

				$sessionId = $this->generateUuidV4();

				// クッキーをセット（JSからも読めるように HttpOnlyはfalse）
				setcookie($cookieName, $sessionId, [
						'expires'  => time() + 60 * 60 * 24 * 365,
						'path'     => '/',
						'secure'   => true,
						'httponly' => false,
						'samesite' => 'Lax',
				]);

				error_log('[session]:'.$sessionId);
				return $sessionId;
		}

		private function generateUuidV4(): string {
				// ランダムなUUIDv4の生成
				$data = random_bytes(16);
				$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
				$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
				return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}
}