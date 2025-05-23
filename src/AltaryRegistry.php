<?php
namespace Altary;

use Altary\Internal\Client;

class AltaryRegistry {
    private static ?Client $client = null;

    public static function setClient(Client $client): void {
        self::$client = $client;
    }

    public static function getClient(): ?Client {
        return self::$client;
    }
}
