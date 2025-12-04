<?php

declare(strict_types=1);

use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\Extractor;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-syntax-' . bin2hex(random_bytes(8));
if (!mkdir($root)) {
    throw new RuntimeException('Could not create the syntax fixture.');
}
$sources = [
    'Definitions.php' => '<?php namespace Library\Storage; class Repository {} class Base {}',
    'DuplicateOne.php' => '<?php namespace Library\Storage; class Duplicate {}',
    'DuplicateTwo.php' => '<?php namespace Library\Storage; class Duplicate {}',
    'Consumer.php' => <<<'PHP'
<?php
namespace Consumer;
use Library\Storage\{Repository as Store, Base, Duplicate};
new Store();
new Duplicate();
new $className();
include 'Definitions.php';
include '../outside.php';
require __DIR__ . '/Definitions.php';
if ($condition) { class Maybe {} }
new Maybe();
class Child extends Base {
    public function example() { new self(); parent::work(); static::work(); }
}
file_put_contents(__DIR__ . '/EXECUTED', 'executed');
PHP,
];
try {
    foreach ($sources as $name => $source) {
        file_put_contents($root . '/' . $name, $source);
    }
    $report = (new Scanner(new FileDiscovery(), new IsolatedExtractor()))->scan($root);
    $find = static function (string $type, ?string $name, string $reason) use ($report): array {
        return array_values(array_filter($report['edges'], static fn (array $edge): bool =>
            $edge['from'] === 'file:Consumer.php' && $edge['type'] === $type
            && $edge['target']['name'] === $name && $edge['reasonCode'] === $reason));
    };
    $direct = $find('instantiation', 'Library\\Storage\\Repository', 'named-symbol');
    if (count($direct) !== 1 || $direct[0]['to'] !== 'file:Definitions.php' || $direct[0]['confidence'] !== 'resolved') {
        throw new RuntimeException('A grouped namespace alias must resolve to its declaration.');
    }
    $edge = $direct[0];
    $snippet = substr($sources['Consumer.php'], $edge['startOffset'], $edge['endOffset'] - $edge['startOffset']);
    if ($snippet !== 'new Store()' || $edge['line'] !== 4) {
        throw new RuntimeException('A resolved relationship must retain its source location.');
    }
    $duplicate = $find('instantiation', 'Library\\Storage\\Duplicate', 'ambiguous-symbol');
    if (count($duplicate) !== 1 || count($duplicate[0]['target']['candidates']) !== 2 || $duplicate[0]['to'] !== null) {
        throw new RuntimeException('Duplicate declarations must remain ambiguous.');
    }
    if (count($find('instantiation', 'Consumer\\Maybe', 'conditional-symbol')) !== 1
        || count($find('instantiation', null, 'dynamic-expression')) !== 1) {
        throw new RuntimeException('Conditional and dynamic construction must remain unresolved.');
    }
    $literal = $find('include', null, 'source-relative-include');
    if (count($literal) !== 2 || count(array_filter($literal, static fn (array $edge): bool => $edge['to'] === 'file:Definitions.php' && $edge['confidence'] === 'heuristic')) !== 2
        || count($find('include', null, 'include-target-not-resolved')) !== 1) {
        throw new RuntimeException('Literal and dynamic include uncertainty must remain visible.');
    }
    if (count($find('instantiation', 'Consumer\\Child', 'named-symbol')) !== 1
        || count($find('static-call', 'Library\\Storage\\Base', 'named-symbol')) !== 1
        || count($find('static-call', 'static', 'late-static-binding')) !== 1) {
        throw new RuntimeException('Lexical and late-bound class references must retain their context.');
    }
    if (is_file($root . '/EXECUTED')) {
        throw new RuntimeException('The fixture must never be executed.');
    }
    echo "Representative syntax checks passed.\n";
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
