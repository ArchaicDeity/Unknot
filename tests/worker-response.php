<?php

declare(strict_types=1);

use Unknot\Scanner\Extractor;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\WorkerResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$source = '<?php namespace Example; class Item {} new Item();';
$response = new WorkerResponse();
$extracted = (new Extractor())->extract($source);
$json = json_encode($extracted, JSON_THROW_ON_ERROR);
if ($response->decode($json, strlen($json), $source) !== $extracted) {
    throw new RuntimeException('A valid worker result must retain its declarations and references.');
}
if ((new IsolatedExtractor())->extract($source)['state'] !== 'clean') {
    throw new RuntimeException('A real worker result must pass validation.');
}
$failed = (new Extractor())->extract('<?php class {');
$failedJson = json_encode($failed, JSON_THROW_ON_ERROR);
if ($response->decode($failedJson, strlen($failedJson), '<?php class {') !== $failed) {
    throw new RuntimeException('A valid parse failure must pass validation.');
}

$cases = [];
$cases[] = '{"state":"clean","declarations":{},"references":[]}';
$cases[] = '{"state":"clean","declarations":[null],"references":[]}';
$cases[] = '{"state":"failed","line":1,"reason":"private data","declarations":[],"references":[]}';
$cases[] = '{"state":"failed","line":1,"declarations":[{}],"references":[]}';
$cases[] = '{"state":"clean","declarations":[{"kind":"class","name":"Item","line":1}],"references":[]}';
$cases[] = '{"state":"clean","declarations":[],"references":[{"type":"instantiation","name":"Item","line":1,"startOffset":0,"endOffset":9999,"expectedKind":"class"}]}';
$cases[] = '{"state":"clean","declarations":[],"references":[{"type":"include","name":null,"line":99,"startOffset":0,"endOffset":1}]}';
$cases[] = '{"state":"clean","declarations":[],"references":[{"type":"static-call","name":"Item","line":1,"startOffset":0,"endOffset":1,"contextReason":"private data"}]}';
$cases[] = '{"state":"clean","declarations":[],"references":[{"type":"extends","name":null,"line":1,"startOffset":0,"endOffset":1,"expectedKind":"class"}]}';
$cases[] = '{"state":"clean","declarations":[],"references":[';
foreach ($cases as $invalid) {
    $decoded = json_decode($invalid);
    if ($decoded instanceof stdClass && ($decoded->state ?? null) === 'clean') {
        $decoded->methodEvidence = (object) ['methods' => [], 'calls' => []];
        $invalid = json_encode($decoded, JSON_THROW_ON_ERROR);
    }
    if ($response->decode($invalid, strlen($invalid), $source) !== null) {
        throw new RuntimeException('A malformed worker result was accepted.');
    }
}
if ($response->decode($json, strlen($json) + 1, $source) !== null) {
    throw new RuntimeException('A response with an incorrect declared length must be rejected.');
}
$wrongLine = '{"state":"clean","declarations":[],"references":[{"type":"include","name":null,"line":2,"startOffset":0,"endOffset":1}],"methodEvidence":{"methods":[],"calls":[]}}';
if ($response->decode($wrongLine, strlen($wrongLine), "<?php\n") !== null) {
    throw new RuntimeException('A reference line must match its source offset.');
}
$orderedSource = '<?php new First(); new Second();';
$unordered = (new Extractor())->extract($orderedSource);
$unordered['references'] = array_reverse($unordered['references']);
$unorderedJson = json_encode($unordered, JSON_THROW_ON_ERROR);
if ($response->decode($unorderedJson, strlen($unorderedJson), $orderedSource) !== null) {
    throw new RuntimeException('Out-of-order worker references must be rejected.');
}
echo "Worker response validation checks passed.\n";
