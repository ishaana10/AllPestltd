<?php
declare(strict_types=1);

/** Shared database handle for All Pest services. Production resolves NuDatabase lazily; tests inject a stand-in. */
final class ApDb
{
    private static ?object $db = null;

    public static function set(?object $db): void
    {
        self::$db = $db;
    }

    public static function get(): object
    {
        if (self::$db === null) {
            self::$db = NuDatabase::getInstance();
        }
        return self::$db;
    }
}
