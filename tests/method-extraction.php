<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$source = <<<'PHP'
<?php
namespace Demo;
use Vendor\Service as Alias;
trait Helper { public static function helper() {} }
interface Contract { public function work(); }
class Example implements Contract {
    use Helper;
    public function work() {
        Alias::run();
        $callable = Alias::run(...);
        $method = 'run';
        Alias::$method();
        $class = Alias::class;
        $class::run();
        $callback = function () { Alias::nested(); };
    }
}
new class { public function hidden() {} };
PHP;
$lexer = new PhpParser\Lexer\Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos']]);
$parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7, $lexer);
$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());
$traverser->addVisitor(new PhpParser\NodeVisitor\ParentConnectingVisitor());
$nodes = $traverser->traverse($parser->parse($source));
$result = (new Unknot\Scanner\MethodEvidence())->collect($nodes);
if (count($result['methods']) !== 3 || count($result['calls']) !== 4) {
    throw new RuntimeException('Direct methods or static call evidence were not bounded as expected.');
}
if ($result['calls'][0]['className'] !== 'Vendor\\Service' || $result['calls'][0]['methodName'] !== 'run'
    || $result['calls'][0]['caller'] !== ['owner' => 'Demo\\Example', 'method' => 'work']
    || $result['calls'][1]['reason'] !== 'dynamic-method'
    || $result['calls'][2]['reason'] !== 'dynamic-class' || $result['calls'][3]['caller'] !== null) {
    throw new RuntimeException('Call evidence incorrectly represents names, context or uncertainty.');
}
foreach ($result['calls'] as $call) {
    if ($call['confidence'] !== 'unresolved' || $call['target'] !== null) {
        throw new RuntimeException('Unconfirmed calls must not acquire a target.');
    }
}
$declarations = (new Unknot\Scanner\Extractor())->extract($source)['declarations'];
$validator = new Unknot\Scanner\MethodEvidenceValidator();
if (!$validator->valid($result, $declarations, $source)) {
    throw new RuntimeException('Valid parser evidence was rejected.');
}
$bad = $result;
$bad['methods'][0]['line']++;
if ($validator->valid($bad, $declarations, $source)) throw new RuntimeException('Forged method line was accepted.');
$bad = $result;
$bad['calls'][0]['confidence'] = 'resolved';
if ($validator->valid($bad, $declarations, $source)) throw new RuntimeException('Forged confidence was accepted.');
$bad = $result;
$bad['calls'][0]['endOffset'] = strlen($source) + 1;
if ($validator->valid($bad, $declarations, $source)) throw new RuntimeException('Out-of-bounds call was accepted.');
$bad = $result;
$bad['calls'][0]['caller']['method'] = 'invented';
if ($validator->valid($bad, $declarations, $source)) throw new RuntimeException('Invented caller was accepted.');
$padded = "<?php\n" . str_repeat("\n", 4100) . 'final class Padded { public static function run() { Padded::run(); } }';
$paddedResult = (new Unknot\Scanner\Extractor())->extract($padded);
if (!$validator->valid($paddedResult['methodEvidence'], $paddedResult['declarations'], $padded)) {
    throw new RuntimeException('Valid positions across a line-index block boundary were rejected.');
}
echo "PHP 8.1 method evidence probe passed; callable creation and anonymous methods were excluded.\n";
