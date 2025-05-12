<?php

declare(strict_types=1);

use Unknot\Scanner\IsolatedExtractor;

require dirname(__DIR__) . '/vendor/autoload.php';

$extractor = new IsolatedExtractor();
if ($extractor->extract('<?php class Example {}')['state'] !== 'clean') {
    throw new RuntimeException('A normal parser worker must succeed.');
}
$source = '<?php $x = ' . str_repeat('(', 300000) . '1' . str_repeat(')', 300000) . ';';
$result = $extractor->extract($source);
if ($result['state'] !== 'failed' || $result['reason'] !== 'parser-worker-failed') {
    throw new RuntimeException('An exhausted parser worker must produce a failure.');
}
$timeout = (new IsolatedExtractor(0.000001))->extract('<?php class Slow {}');
if ($timeout['state'] !== 'failed' || $timeout['reason'] !== 'parser-timeout') {
    throw new RuntimeException('A parser deadline must produce a timeout.');
}
if ($extractor->extract('<?php class NextFile {}')['state'] !== 'clean') {
    throw new RuntimeException('A failed worker must not prevent the next file from parsing.');
}
echo "Worker isolation checks passed.\n";
