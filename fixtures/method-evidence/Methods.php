<?php
file_put_contents(__DIR__ . '/CANARY_EXECUTED', 'This fixture must never run.');

final class Example
{
    public static function target(): void {}
    public function run(): void
    {
        self::target();
        $callable = self::target(...);
        $method = 'target';
        self::$method();
    }
}
