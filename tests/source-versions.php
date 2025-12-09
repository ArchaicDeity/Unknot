<?php

declare(strict_types=1);

use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-versions-' . bin2hex(random_bytes(8));
if (!mkdir($root)) {
    throw new RuntimeException('Could not create the syntax-version fixture.');
}
$sources = [
    'Dependency.php' => '<?php namespace Versions; class Dependency {}',
    'Legacy.php' => '<?php namespace Versions; class Legacy { function make() { $items = array(1, 2); return new Dependency(); } }',
    'Php74.php' => '<?php namespace Versions; class Php74 { function make() { $fn = fn($x) => $x + 1; return new Dependency(); } }',
    'Php81.php' => '<?php namespace Versions; enum Php81 { case Ready; function make() { return new Dependency(); } }',
    'Php82.php' => '<?php namespace Versions; readonly class Php82 { function make() { return new Dependency(); } }',
    'Php83.php' => '<?php namespace Versions; class Php83 { const string NAME = "x"; function make() { return new Dependency(); } }',
    'Php84.php' => '<?php namespace Versions; class Php84 { public string $name { get => $this->name; } function make() { return new Dependency(); } }',
    'Php85.php' => '<?php namespace Versions; class Php85 { function make() { $name = "x" |> strtoupper(...); return new Dependency(); } } file_put_contents(__DIR__ . "/EXECUTED", "yes");',
];
try {
    foreach ($sources as $name => $source) {
        file_put_contents($root . '/' . $name, $source);
    }
    $report = (new Scanner(new FileDiscovery(), new IsolatedExtractor()))->scan($root);
    foreach (array_keys($sources) as $name) {
        $nodes = array_values(array_filter($report['nodes'], static fn (array $node): bool => $node['id'] === 'file:' . $name));
        if (count($nodes) !== 1 || $nodes[0]['parseState'] !== 'clean') {
            throw new RuntimeException('A representative source version did not parse: ' . $name);
        }
        if ($name === 'Dependency.php') {
            continue;
        }
        $edges = array_values(array_filter($report['edges'], static fn (array $edge): bool =>
            $edge['from'] === 'file:' . $name && $edge['to'] === 'file:Dependency.php'
            && $edge['type'] === 'instantiation' && $edge['confidence'] === 'resolved'));
        if (count($edges) !== 1 || $edges[0]['line'] !== 1 || $edges[0]['endOffset'] <= $edges[0]['startOffset']) {
            throw new RuntimeException('A direct relationship lost its evidence: ' . $name);
        }
    }
    if (file_exists($root . '/EXECUTED')) {
        throw new RuntimeException('Source fixtures must not execute.');
    }
    echo "Representative source-version checks passed.\n";
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
