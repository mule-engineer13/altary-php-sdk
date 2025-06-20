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

    /** @var ErrorHandler|null */
    private static ?ErrorHandler $instance = null;

    /** @var bool */
    private bool $isErrorHandlerRegistered = false;

    /** @var bool */
    private bool $isExceptionHandlerRegistered = false;

    /** @var callable|null */
    private $previousErrorHandler = null;

    /** @var callable|null */
    private $previousExceptionHandler = null;

    public function __construct(?Client $client = null)
    {
        // DI 未使用の場合は fromEnv() で自動生成
        $this->client = $client ?? Client::fromEnv();
    }

    /**
     * Singleton パターンでエラーハンドラを登録（重複登録を防ぐ）
     */
    public static function registerOnce(?Client $client = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($client);
        }

        if (self::$instance->isErrorHandlerRegistered && self::$instance->isExceptionHandlerRegistered) {
            return self::$instance;
        }

        self::$instance->register();
        return self::$instance;
    }

    /**
     * エラーハンドラ・例外ハンドラ・shutdown フックを登録
     * 他プラグイン・アプリに上書きされるのを防ぐため、必要なら再実行推奨
     */
    public function register(): void
    {
        // 致命的エラー時にFatal Errorを捕捉してからバッファを flush
        register_shutdown_function([$this, 'handleShutdown']);

        // 通常 PHP エラー（Deprecated も含める）
        $this->registerErrorHandler();

        // 例外ハンドラ
        $this->registerExceptionHandler();
    }

    /**
     * エラーハンドラの登録（既存ハンドラとの連携とPHP bug対応）
     */
    private function registerErrorHandler(): void
    {
        if ($this->isErrorHandlerRegistered) {
            return;
        }

        $errorHandlerCallback = \Closure::fromCallable([$this, 'handleError']);

        $this->isErrorHandlerRegistered = true;
        $this->previousErrorHandler = set_error_handler($errorHandlerCallback);

        // PHP bug #63206 対応
        // 最初のハンドラでない場合、E_ALLを指定するとバグが発生する可能性があるため
        // 一度リストアしてから再登録する
        if ($this->previousErrorHandler === null) {
            restore_error_handler();
            set_error_handler($errorHandlerCallback, E_ALL);
        }
    }

    /**
     * 例外ハンドラの登録（既存ハンドラとの連携）
     */
    private function registerExceptionHandler(): void
    {
        if ($this->isExceptionHandlerRegistered) {
            return;
        }

        $this->isExceptionHandlerRegistered = true;
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
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

        // Altary にエラーを送信
        $this->client->send([
            'type'    => 'error',
            'errno'   => $errno,
            'message' => $errstr,
            'file'    => $errfile,
            'line'    => $errline,
            'url'     => ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
            'originalException' => null, // PHPエラーの場合はnull
        ]);

        // 既存のエラーハンドラがあれば呼び出す
        if ($this->previousErrorHandler !== null && is_callable($this->previousErrorHandler)) {
            return call_user_func($this->previousErrorHandler, $errno, $errstr, $errfile, $errline);
        }

        return false; // PHP デフォルトのハンドリングを継続
    }

    /**
     * 未捕捉例外ハンドラ
     */
    public function handleException(\Throwable $e): void
    {
        // Altary に例外を送信
        $this->client->send([
            'type'    => 'exception',
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
            'url'     => ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
            'originalException' => $e, // 例外オブジェクト自体を渡す
        ]);

        // 既存の例外ハンドラがあれば呼び出す
        if ($this->previousExceptionHandler !== null && is_callable($this->previousExceptionHandler)) {
            call_user_func($this->previousExceptionHandler, $e);
        }
    }

    /**
     * shutdown 時のFatal Error捕捉
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        
        error_log("[Shutdown] error_get_last: " . json_encode($error));
        
        // Fatal Error, Parse Error, Compile Error を捕捉
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            error_log("[Shutdown] Sending fatal error: " . $error['message']);
            $this->client->send([
                'type'    => 'error',
                'errno'   => $error['type'],
                'message' => $error['message'],
                'file'    => $error['file'],
                'line'    => $error['line'],
                'url'     => ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
                'originalException' => null,
            ]);
        } else {
            error_log("[Shutdown] No fatal error to send");
        }
        
        // バッファを flush
        error_log("[Shutdown] Flushing client");
        $this->client->flush();
    }
}

