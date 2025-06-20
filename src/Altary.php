<?php
namespace Altary;

use Altary\Internal\Client;
use Altary\Internal\ErrorHandler;
use Altary\AltaryRegistry;

class Altary
{
    public static function init(array $options = []): void {
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

        if (isset($options['pre_send']) && is_callable($options['pre_send'])) {
            $client->setPreSendCallback($options['pre_send']);
        }

        if (isset($options['type_of_errors'])) {
            $client->setAllowedErrorTypes($options['type_of_errors']);
        }

        ErrorHandler::registerOnce($client);

        AltaryRegistry::setClient($client);
    }

    public static function handleException(\Throwable $e): void {
        $client = AltaryRegistry::getClient();
        if ($client) {
            $client->send([
                'type' => 'exception',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'url' => (($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''))
            ]);
        }
    }
}
