<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$source = <<<'PHP'
<?php
namespace Counterexamples;
trait Shared { public static function helper() {} }
class Base { public static function target() {} }
class Child extends Base {
    use Shared { helper as alias; }
    public static function __callStatic($name, $arguments) {}
    public function run() {
        parent::target();
        static::target();
        self::alias();
        self::missing();
        $name = 'target';
        self::$name();
        $callable = self::target(...);
    }
}
class Duplicate { public static function same() {} }
class Duplicate { public static function same() {} }
enum ExampleEnum { public function excluded() {} }
Duplicate::same();
PHP;
$lexer = new PhpParser\Lexer\Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos']]);
$parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7, $lexer);
$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());
$traverser->addVisitor(new PhpParser\NodeVisitor\ParentConnectingVisitor());
$evidence = (new Unknot\Scanner\MethodEvidence())->collect($traverser->traverse($parser->parse($source)));
$declarations = (new Unknot\Scanner\Extractor())->extract($source)['declarations'];
if (!(new Unknot\Scanner\MethodEvidenceValidator())->valid($evidence, $declarations, $source)) {
    throw new RuntimeException('Valid counterexample evidence failed validation.');
}
if (count($evidence['calls']) !== 6) throw new RuntimeException('Unexpected call count, including callable creation.');
foreach ($evidence['calls'] as $call) {
    if ($call['target'] !== null || $call['confidence'] !== 'unresolved') throw new RuntimeException('A risky target was falsely resolved.');
}
foreach ($evidence['methods'] as $method) {
    if ($method['owner'] === 'Counterexamples\\Child' && $method['name'] === 'alias') throw new RuntimeException('A trait alias was reported as directly declared.');
    if ($method['owner'] === 'Counterexamples\\ExampleEnum') throw new RuntimeException('Enum methods must not be included in version 1.');
}
echo "Inheritance, trait alias, dynamic, magic and duplicate targets remained unresolved.\n";
