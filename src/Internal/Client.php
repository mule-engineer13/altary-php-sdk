<?php
namespace Altary\Internal;


use GuzzleHttp\Client as Guzzle;

class Client {
    private string $apiKey;
    private string $endpoint;
    private Guzzle $http;

    private array $userContext = [];
    private array $logLevels = ['error', 'exception'];
    private array $batch = [];
    private int $batchSize = 5;

    public function __construct(string $apiKey, string $endpoint = 'https://altary.web-ts.dev/cards/errors') {
        $this->apiKey = $apiKey;
        $this->endpoint = $endpoint;
        $this->http = new Guzzle();
    }

    public function setUser(array $user): void {
        $this->userContext = $user;
    }

    public function setLogLevels(array $levels): void {
        $this->logLevels = $levels;
    }



		public function send(array $data): void
		{
				$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
				$headers = [
						'Content-Type: application/json',
						'Accept: application/json',
						'Authorization: Bearer ' . $this->apiKey,
				];

				$ch = curl_init($this->endpoint);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
				curl_setopt($ch, CURLOPT_TIMEOUT, 15);

				curl_exec($ch); // レスポンスもエラーも無視（非同期的・安全な設計）
				curl_close($ch);
		}


    public function flush(): void {
        if (empty($this->batch)) return;

        try {
            $this->http->post($this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $this->batch
            ]);
        } catch (\Throwable $e) {
            // fail silently
        }

        $this->batch = [];
    }

    public function getFileContentSafe(?string $file): ?string {
        if (!$file || !is_readable($file)) return null;
        if (filesize($file) > 512 * 1024) return '[[File too large]]';
        return file_get_contents($file);
    }

    private function getEnvironmentInfo(): array {
        return [
            'php_version' => phpversion(),
            'os' => php_uname(),
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'timestamp' => time(),
        ];
    }
}
