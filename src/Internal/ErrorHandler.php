<?php
namespace Altary\Internal;

require_once __DIR__ . '/Client.php';

/**
 * Error/Exception ハンドラ
 * WordPress 版と同じ仕様で JST タイムスタンプ・Deprecated まで送信
 */
class ErrorHandler
{
    /** @var Client */
    private Client $client;

    public function __construct(?Client $client = null)
    {
        // DI 未使用の場合は fromEnv() で自動生成
        $this->client = $client ?? Client::fromEnv();
    }

    /**
     * エラーハンドラ・例外ハンドラ・shutdown フックを登録
     * 他プラグイン・アプリに上書きされるのを防ぐため、必要なら再実行推奨
     */
    public function register(): void
    {
        // 致命的エラー時にバッファを flush
        register_shutdown_function([$this->client, 'flush']);

        // 通常 PHP エラー（Deprecated も含める）
        set_error_handler([$this, 'handleError'], E_ALL);

        // 例外ハンドラ
        set_exception_handler([$this, 'handleException']);
    }

    /**
     * PHP エラーハンドラ
     * @return bool true で PHP デフォルト処理を停止
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // error_reporting がフィルタしている場合は無視
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $this->client->send([
            'type'    => 'error',
            'errno'   => $errno,
            'message' => $errstr,
            'file'    => $errfile,
            'line'    => $errline,
            'url'     => ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        ]);
        return true; // PHP デフォルトのハンドリングを無効化
    }

    /**
     * 未捕捉例外ハンドラ
     */
    public function handleException(\Throwable $e): void
    {
        $this->client->send([
            'type'    => 'exception',
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
            'url'     => ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        ]);
    }
}

