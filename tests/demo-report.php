<?php

declare(strict_types=1);

use Unknot\Report\ReportReader;

require dirname(__DIR__) . '/vendor/autoload.php';

$report = (new ReportReader())->read(dirname(__DIR__) . '/demo/laravel-database.json');
if ($report['schemaVersion'] !== 1 || $report['scan']['fileCount'] !== 254 || count($report['edges']) !== 1572) {
    throw new RuntimeException('The Laravel demo report does not match its documented scope.');
}
$manager = array_values(array_filter($report['nodes'], static fn (array $node): bool => $node['path'] === 'Capsule/Manager.php'));
if (count($manager) !== 1 || count(array_filter($manager[0]['methods'], static fn (array $method): bool =>
    $method['owner'] === 'Illuminate\\Database\\Capsule\\Manager' && $method['name'] === 'connection'
    && $method['line'] === 71 && $method['visibility'] === 'public' && $method['static'] === true)) !== 1) {
    throw new RuntimeException('The documented Laravel method declaration is missing.');
}
foreach ($report['nodes'] as $node) {
    if ($node['parseState'] !== 'clean') {
        throw new RuntimeException('The Laravel demo includes an incomplete file.');
    }
}
$known = array_filter($report['edges'], static fn (array $edge): bool =>
    $edge['from'] === 'file:Capsule/Manager.php'
    && $edge['to'] === 'file:Connectors/ConnectionFactory.php'
    && $edge['type'] === 'instantiation'
    && $edge['line'] === 60
    && $edge['confidence'] === 'resolved'
);
if (count($known) !== 1) {
    throw new RuntimeException('The documented Laravel example is missing.');
}
echo "Laravel demo report checks passed.\n";
