<?php

declare(strict_types=1);

use Unknot\Report\ReportReader;
use Unknot\Scanner\Extractor;
use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;
use Unknot\Scanner\WorkerResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkMethods(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$source = <<<'PHP'
<?php
namespace Example;
interface Contract { public function execute(); }
trait Shared { protected function helper() {} }
class ParentType { public function inherited() {} }
enum State { case Ready; public static function label() {} }
class Child extends ParentType implements Contract {
    use Shared { helper as renamedHelper; }
    public function execute() {
        $anonymous = new class { public function hiddenMethod() {} };
        $this->dynamicCall();
        self::staticMethod();
    }
    private static function staticMethod() {}
    #[\Deprecated]
    public function
    annotated() {}
    public function &reference() { return $this; }
}
PHP;
$extractor = new Extractor();
$extracted = $extractor->extract($source);
checkMethods($extracted['state'] === 'clean', 'The method fixture must parse.');
$methods = $extracted['methods'];
$names = array_map(static fn (array $method): string => $method['owner'] . '::' . $method['name'], $methods);
checkMethods($names === [
    'Example\\Contract::execute', 'Example\\Shared::helper', 'Example\\ParentType::inherited',
    'Example\\State::label', 'Example\\Child::execute', 'Example\\Child::staticMethod', 'Example\\Child::annotated', 'Example\\Child::reference',
], 'Only directly declared methods of named types should be indexed.');
checkMethods($methods[1]['visibility'] === 'protected' && !$methods[1]['static'], 'Trait method visibility must be retained.');
checkMethods($methods[3]['ownerKind'] === 'enum' && $methods[3]['static'], 'Static enum methods must retain their metadata.');
checkMethods($methods[5]['visibility'] === 'private' && $methods[5]['static'], 'Private static methods must retain their metadata.');
checkMethods($methods[6]['line'] === 17, 'An attributed method should point at its name, even when the signature spans lines.');
foreach ($methods as $method) {
    checkMethods(substr($source, $method['startOffset'], $method['endOffset'] - $method['startOffset']) === $method['name'], 'Method offsets must identify the declaration name.');
    checkMethods(substr_count(substr($source, 0, $method['startOffset']), "\n") + 1 === $method['line'], 'Method lines must match their source offsets.');
}
checkMethods(!in_array('Example\\Child::renamedHelper', $names, true), 'Trait aliases must not be invented as declarations.');
checkMethods(!in_array('Example\\Child::inherited', $names, true), 'Inherited methods must not be copied into a child.');
checkMethods(!in_array('Example\\Child::dynamicCall', $names, true), 'A dynamic call must not be mistaken for a declaration.');

$worker = new WorkerResponse();
$json = json_encode($extracted, JSON_THROW_ON_ERROR);
$decoded = $worker->decode($json, strlen($json), $source);
checkMethods($decoded !== null && $decoded['methods'] === $methods && $decoded['references'] == $extracted['references'], 'Method metadata must survive worker validation.');
checkMethods((new IsolatedExtractor())->extract($source)['methods'] === $methods, 'The isolated worker must return the same method declarations.');
foreach (['name', 'owner', 'line', 'startOffset', 'visibility', 'static'] as $field) {
    $bad = $extracted;
    $bad['methods'][0][$field] = match ($field) {
        'name' => 'forged', 'owner' => 'Example\\Other', 'line' => 999,
        'startOffset' => 0, 'visibility' => 'invented', 'static' => 'yes',
    };
    $invalid = json_encode($bad, JSON_THROW_ON_ERROR);
    checkMethods($worker->decode($invalid, strlen($invalid), $source) === null, 'Invalid worker method field must be rejected: ' . $field);
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-methods-' . bin2hex(random_bytes(8));
checkMethods(mkdir($scratch), 'Could not create method fixture directory.');
try {
    file_put_contents($scratch . '/Methods.php', $source);
    $report = (new Scanner(new FileDiscovery(), new IsolatedExtractor()))->scan($scratch);
    checkMethods($report['schemaVersion'] === 1 && count($report['nodes'][0]['methods']) === 8, 'A new report must include the declared methods.');
    checkMethods(!array_key_exists('startOffset', $report['nodes'][0]['methods'][0]), 'Worker-only offsets must not enter the report.');
    (new ReportReader())->validate($report);
    $old = $report;
    $old['schemaVersion'] = 0;
    unset($old['nodes'][0]['methods']);
    (new ReportReader())->validate($old);
    foreach (['missing', 'owner', 'static'] as $field) {
        $bad = $report;
        if ($field === 'missing') unset($bad['nodes'][0]['methods']);
        elseif ($field === 'owner') $bad['nodes'][0]['methods'][0]['owner'] = 'Example\\Unknown';
        else $bad['nodes'][0]['methods'][0]['static'] = 'yes';
        try {
            (new ReportReader())->validate($bad);
            throw new LogicException('Invalid report method accepted: ' . $field);
        } catch (RuntimeException $error) {
            // Invalid imported metadata must be rejected by the report boundary.
        }
    }
} finally {
    if (is_file($scratch . '/Methods.php')) unlink($scratch . '/Methods.php');
    rmdir($scratch);
}
echo "Method declaration checks passed.\n";
