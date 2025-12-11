<?php

declare(strict_types=1);

use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\Scanner;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-context-' . bin2hex(random_bytes(8));
if (!mkdir($root)) {
    throw new RuntimeException('Could not create the class-context fixture.');
}
$sources = [
    'Base.php' => '<?php namespace Demo; class Base {}',
    'Child.php' => '<?php namespace Demo; class Child extends Base { function example() { self::run(); parent::run(); static::run(); } } trait Shared { function example() { self::run(); } }',
];
try {
    foreach ($sources as $name => $source) {
        file_put_contents($root . '/' . $name, $source);
    }
    $report = (new Scanner(new FileDiscovery(), new IsolatedExtractor()))->scan($root);
    $find = static fn (string $syntax): array => array_values(array_filter($report['edges'], static fn (array $edge): bool =>
        $edge['from'] === 'file:Child.php' && ($edge['target']['syntax'] ?? null) === $syntax));
    $self = $find('self');
    $parent = $find('parent');
    $static = $find('static');
    if (count($self) !== 2 || count(array_filter($self, static fn (array $edge): bool => $edge['to'] === 'file:Child.php')) !== 1
        || count(array_filter($self, static fn (array $edge): bool => $edge['reasonCode'] === 'trait-context-dependent')) !== 1
        || count($parent) !== 1 || $parent[0]['to'] !== 'file:Base.php'
        || count($static) !== 1 || $static[0]['reasonCode'] !== 'late-static-binding' || $static[0]['to'] !== null) {
        throw new RuntimeException('Class-context relationships did not retain their expected certainty.');
    }
    echo "Class-context checks passed.\n";
} finally {
    foreach (array_keys($sources) as $name) {
        if (is_file($root . '/' . $name)) {
            unlink($root . '/' . $name);
        }
    }
    rmdir($root);
}
