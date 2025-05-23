<?php
namespace Altary\Internal;

class ErrorHandler {
    private Client $client;

    public function __construct(Client $client) {
        $this->client = $client;
    }

    public function register(): void {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this->client, 'flush']);
    }

    public function handleException(\Throwable $e): void {
        $file = $e->getFile();
        $this->client->send([
            'type' => 'exception',
            'message' => $e->getMessage(),
            'file' => $file,
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'file_content' => $this->getSafeFileContent($file),
        ]);
    }

    public function handleError(int $errno, string $errstr, string $file, int $line): void {
        $this->client->send([
            'type' => 'error',
            'errno' => $errno,
            'message' => $errstr,
            'file' => $file,
            'line' => $line,
            'file_content' => $this->getSafeFileContent($file),
        ]);
    }

    private function getSafeFileContent(string $filePath): ?string {
        if (!is_readable($filePath)) return null;
        if (filesize($filePath) > 512 * 1024) return '[[File too large]]';
        return file_get_contents($filePath);
    }
}
