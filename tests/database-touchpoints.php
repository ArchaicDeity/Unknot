<?php

declare(strict_types=1);

use Unknot\Scanner\IsolatedExtractor;

require dirname(__DIR__) . '/vendor/autoload.php';

$source = <<<'PHP'
<?php
use PDO as Database;
$db = new Database('private-dsn', 'private-user', 'private-password');
$db->query('SELECT private_value');
(new \PDO('secret'))->query('SELECT private_value');
mysqli_query($db, 'SELECT private_value');
mysqli_connect('private-host');
$unknown->query('must not count');
new \Example\PDO('must not count');
$changed = new \PDO('secret');
$changed = $unknown;
$changed->query('must not count');
$branch = new \PDO('secret');
if ($flag) { $branch = $unknown; }
$branch->query('must not count');
PHP;
$result = (new IsolatedExtractor())->extract($source);
if ($result['state'] !== 'clean') {
    throw new RuntimeException('Database fixture could not be parsed by the isolated worker.');
}
$apis = array_column($result['findings'], 'api');
foreach (['pdo::__construct', 'pdo::query', 'mysqli_query', 'mysqli_connect'] as $api) {
    if (!in_array($api, $apis, true)) {
        throw new RuntimeException('Missing expected database indicator: ' . $api);
    }
}
if (count($apis) !== 8 || str_contains(json_encode($result['findings'], JSON_THROW_ON_ERROR), 'private')) {
    throw new RuntimeException('Database indicators must omit arguments and ignore unknown receivers.');
}
if (count(array_filter($result['findings'], static fn (array $finding): bool => $finding['api'] === 'pdo::query')) !== 2) {
    throw new RuntimeException('Overwritten or branch-dependent receivers must not be inferred.');
}
foreach ($result['findings'] as $finding) {
    if ($finding['confidence'] !== 'heuristic' || $finding['line'] < 1) {
        throw new RuntimeException('Indicators need their heuristic status and location.');
    }
}
echo "Database touchpoint checks passed.\n";
