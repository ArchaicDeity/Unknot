<?php

declare(strict_types=1);

use LegacyLens\Scanner\FileDiscovery;
use LegacyLens\Scanner\Extractor;
use LegacyLens\Scanner\Scanner;

require dirname(__DIR__) . '/vendor/autoload.php';

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'legacylens-kinds-' . bin2hex(random_bytes(8));
if (!mkdir($scratch)) {
    throw new RuntimeException('Could not create the symbol fixture.');
}
$sources = [
    'Kinds.php' => <<<'PHP'
<?php
namespace Examples;
interface Contract {}
trait Sharing {}
class Base {}
enum Status implements Contract { case Ready; }
PHP,
    'Valid.php' => <<<'PHP'
<?php
namespace Examples;
interface Extended extends Contract {}
class Child extends Base implements Contract { use Sharing; }
new Base();
Status::example();
PHP,
    'Invalid.php' => <<<'PHP'
<?php
namespace Examples;
new Contract();
new Status();
class WrongParent extends Contract {}
interface WrongInterface extends Base {}
class WrongImplementation implements Base {}
class WrongTrait { use Base; }
PHP,
    'DuplicateClass.php' => '<?php namespace Examples; class Duplicate {}',
    'DuplicateInterface.php' => '<?php namespace Examples; interface Duplicate {}',
    'Ambiguous.php' => '<?php namespace Examples; new Duplicate(); if (true) { class Maybe {} } new Maybe();',
];
try {
    foreach ($sources as $name => $content) {
        file_put_contents($scratch . '/' . $name, $content);
    }
    $report = (new Scanner(new FileDiscovery(), new Extractor()))->scan($scratch);
    $find = static function (string $file, string $type, string $name) use ($report): array {
        return array_values(array_filter($report['edges'], static fn (array $edge): bool =>
            $edge['from'] === 'file:' . $file && $edge['type'] === $type && $edge['target']['name'] === 'Examples\\' . $name));
    };
    foreach ([
        ['Kinds.php', 'implements', 'Contract'],
        ['Valid.php', 'extends', 'Contract'],
        ['Valid.php', 'extends', 'Base'],
        ['Valid.php', 'implements', 'Contract'],
        ['Valid.php', 'trait-use', 'Sharing'],
        ['Valid.php', 'instantiation', 'Base'],
        ['Valid.php', 'static-call', 'Status'],
    ] as [$file, $type, $name]) {
        $edges = $find($file, $type, $name);
        if (count($edges) !== 1 || $edges[0]['confidence'] !== 'resolved' || $edges[0]['to'] !== 'file:Kinds.php') {
            throw new RuntimeException('A valid declaration relationship was not resolved.');
        }
    }
    foreach ([
        ['instantiation', 'Contract', 'class', 'interface'], ['instantiation', 'Status', 'class', 'enum'],
        ['extends', 'Contract', 'class', 'interface'], ['extends', 'Base', 'interface', 'class'],
        ['implements', 'Base', 'interface', 'class'], ['trait-use', 'Base', 'trait', 'class'],
    ] as [$type, $name, $expectedKind, $foundKind]) {
        $edges = $find('Invalid.php', $type, $name);
        if (count($edges) !== 1 || $edges[0]['reasonCode'] !== 'symbol-kind-mismatch'
            || $edges[0]['confidence'] !== 'unresolved' || $edges[0]['to'] !== null
            || $edges[0]['target']['candidates'] !== ['file:Kinds.php']
            || $edges[0]['target']['expectedKind'] !== $expectedKind || $edges[0]['target']['foundKind'] !== $foundKind) {
            throw new RuntimeException('An incompatible declaration must remain unresolved with candidate evidence.');
        }
    }
    $duplicate = $find('Ambiguous.php', 'instantiation', 'Duplicate');
    $conditional = $find('Ambiguous.php', 'instantiation', 'Maybe');
    if (count($duplicate) !== 1 || $duplicate[0]['reasonCode'] !== 'ambiguous-symbol'
        || count($duplicate[0]['target']['candidates']) !== 2
        || count($conditional) !== 1 || $conditional[0]['reasonCode'] !== 'conditional-symbol') {
        throw new RuntimeException('Duplicate or conditional declarations must remain unresolved.');
    }
    echo "Symbol kind checks passed.\n";
} finally {
    foreach (array_keys($sources) as $name) {
        if (is_file($scratch . '/' . $name)) {
            unlink($scratch . '/' . $name);
        }
    }
    rmdir($scratch);
}
