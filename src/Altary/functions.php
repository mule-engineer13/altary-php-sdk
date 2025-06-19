<?php
namespace Altary;

use Altary\Internal\Client;
use Altary\Internal\ErrorHandler;
use Altary\AltaryRegistry;

function init(array $options = []): void {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $apiKey = $options['rand'] ?? $options['api_key'] ?? null;
    if (!$apiKey) {
        throw new \InvalidArgumentException('Altary init: api_key or rand is required');
    }

    $client = new Client($apiKey);

    $handler = new ErrorHandler($client);
    $handler->register();

    AltaryRegistry::setClient($client);
}

function captureException(\Throwable $exception): void {
    $client = AltaryRegistry::getClient();
    if ($client) {
        $client->send([
            'type' => 'exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'url' => ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        ]);
        $client->flush();
    }
}
