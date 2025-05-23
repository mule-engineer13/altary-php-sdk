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
				$json = json_encode($data, JSON_UNESCAPED_UNICODE);
				$headers = [
						'Content-Type: application/json',
						'Authorization: Bearer ' . $this->apiKey,
				];

				$ch = curl_init($this->endpoint);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

				$response = curl_exec($ch);
				$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$curlError = curl_error($ch);
				curl_close($ch);

				// 🔍 ログ出力
				error_log("[Altary SDK] POST to: " . $this->endpoint);
				error_log("[Altary SDK] Payload: " . $json);
				error_log("[Altary SDK] HTTP Status: " . $httpCode);
				if ($curlError) {
						error_log("[Altary SDK] cURL ERROR: " . $curlError);
				} else {
						error_log("[Altary SDK] Response: " . $response);
				}
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
