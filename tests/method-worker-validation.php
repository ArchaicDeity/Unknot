<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LegacyLens\Scanner\Extractor;
use LegacyLens\Scanner\IsolatedExtractor;

$source = '<?php final class Example { public static function run(): void {} public function test(): void { Example::run(); } }';
$result = (new Extractor())->extract($source);
$decoder = new ReflectionMethod(IsolatedExtractor::class, 'decode');
$decoder->setAccessible(true);
$worker = new IsolatedExtractor();
$decode = static function (array $data) use ($decoder, $worker, $source) {
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    return $decoder->invoke($worker, $json, strlen($json), $source);
};
if ($decode($result) === null) throw new RuntimeException('A valid worker response was rejected.');
$bad = $result;
$bad['methodEvidence']['calls'][0]['confidence'] = 'resolved';
if ($decode($bad) !== null) throw new RuntimeException('A forged target confidence was accepted.');
$bad = $result;
$bad['methodEvidence']['methods'][0]['startOffset'] = strlen($source);
if ($decode($bad) !== null) throw new RuntimeException('An invalid method span was accepted.');
$bad = $result;
$bad['methodEvidence']['calls'][0]['unexpectedSource'] = 'untrusted';
if ($decode($bad) !== null) throw new RuntimeException('An unexpected worker field was accepted.');
$bad = $result;
$bad['references'][0]['line'] = 2;
if ($decode($bad) !== null) throw new RuntimeException('A forged file-reference line was accepted.');
$bad = $result;
$bad['references'][0]['unexpectedSource'] = 'untrusted';
$cleaned = $decode($bad);
if ($cleaned === null || array_key_exists('unexpectedSource', $cleaned['references'][0])) throw new RuntimeException('Unknown file-reference data was forwarded.');
$bad = $result;
unset($bad['methodEvidence']);
if ($decode($bad) !== null) throw new RuntimeException('Missing clean-file evidence was accepted.');
$bad = ['state' => 'failed', 'declarations' => [], 'references' => [], 'methodEvidence' => ['methods' => [], 'calls' => []]];
if ($decode($bad) !== null) throw new RuntimeException('A failed worker claimed method evidence.');
$json = '{"state":"failed","line":1,"declarations":{},"references":[]}';
if ($decoder->invoke($worker, $json, strlen($json), $source) !== null) throw new RuntimeException('An object was accepted where a declaration list was required.');
echo "Legacy method worker response validation checks passed.\n";
