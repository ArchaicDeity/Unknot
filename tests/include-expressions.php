<?php

declare(strict_types=1);

use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-includes-' . bin2hex(random_bytes(8));
if (!mkdir($root)) {
    throw new RuntimeException('Could not create the include fixture.');
}
$sources = [
    'Target.php' => '<?php class Target {}',
    'Consumer.php' => <<<'PHP'
<?php
include __DIR__ . '/Target.php';
include 'Tar' . 'get.php';
include __DIR__ . $runtime;
include __DIR__ . 'Target.php';
include __DIR__ . '/../outside.php';
include makePath();
function makePath() { file_put_contents(__DIR__ . '/EXECUTED', 'yes'); return 'Target.php'; }
PHP,
];
try {
    foreach ($sources as $name => $source) {
        file_put_contents($root . '/' . $name, $source);
    }
    $report = (new Scanner(new FileDiscovery(), new IsolatedExtractor()))->scan($root);
    $includes = array_values(array_filter($report['edges'], static fn (array $edge): bool =>
        $edge['from'] === 'file:Consumer.php' && $edge['type'] === 'include'));
    $byLine = [];
    foreach ($includes as $edge) {
        $byLine[$edge['line']] = $edge;
    }
    foreach ([2, 3] as $line) {
        if (($byLine[$line]['to'] ?? null) !== 'file:Target.php' || ($byLine[$line]['confidence'] ?? null) !== 'heuristic') {
            throw new RuntimeException('An inert include expression did not retain its candidate.');
        }
    }
    foreach ([4, 5, 7] as $line) {
        if (($byLine[$line]['reasonCode'] ?? null) !== 'dynamic-expression' || ($byLine[$line]['to'] ?? null) !== null) {
            throw new RuntimeException('An uncertain include expression must remain unresolved.');
        }
    }
    if (($byLine[6]['reasonCode'] ?? null) !== 'include-target-not-resolved' || ($byLine[6]['to'] ?? null) !== null) {
        throw new RuntimeException('An include outside the source root must remain unresolved.');
    }
    if (file_exists($root . '/EXECUTED')) {
        throw new RuntimeException('An include expression must never be executed.');
    }
    echo "Include expression checks passed.\n";
} finally {
    foreach (array_keys($sources) as $name) {
        if (is_file($root . '/' . $name)) {
            unlink($root . '/' . $name);
        }
    }
    if (is_file($root . '/EXECUTED')) {
        unlink($root . '/EXECUTED');
    }
    rmdir($root);
}
