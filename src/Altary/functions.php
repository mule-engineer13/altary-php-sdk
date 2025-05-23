<?php
namespace Altary;

use Altary\Internal\Client;
use Altary\Internal\ErrorHandler;

function init(array $options = []): void {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $apiKey = $options['api_key'] ?? null;
    if (!$apiKey) {
        throw new \InvalidArgumentException('Altary init: api_key is required');
    }

    $client = new Client($apiKey);

    if (isset($options['user'])) {
        $client->setUser($options['user']);
    }

    if (isset($options['log_levels'])) {
        $client->setLogLevels($options['log_levels']);
    }

    $handler = new ErrorHandler($client);
    $handler->register();

    AltaryRegistry::setClient($client);
}
