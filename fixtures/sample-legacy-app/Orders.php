<?php

namespace Demo;

use Demo\Storage as Database;

$storage = new Database();
include 'Storage.php';
include $runtimePath;

class OrderRepository extends Database
{
    public function example()
    {
        self::create();
        parent::create();
        static::create();
    }
}

function databaseExample(): void
{
    // These calls are fixture data; the scanner never executes this file.
    (new \PDO('sqlite::memory:'))->query('SELECT 1');
    \mysqli_query($connection, 'SELECT 1');
}

// This must never be created when the fixture is scanned.
file_put_contents(__DIR__ . '/CANARY_EXECUTED', 'executed');
