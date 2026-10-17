<?php

declare(strict_types=1);

use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkSourceWorker(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-source-worker-' . bin2hex(random_bytes(8));
checkSourceWorker(mkdir($root), 'Could not create source worker fixture.');
$root = realpath($root);
$path = $root . DIRECTORY_SEPARATOR . 'Example.php';
$canary = $root . DIRECTORY_SEPARATOR . 'executed';
$outside = $root . '-outside.php';
try {
    $source = '<?php class Example {} file_put_contents(' . var_export($canary, true) . ', "unsafe");';
    file_put_contents($path, $source);
    $snapshot = (new FileDiscovery())->discover($root);
    $reader = new IsolatedExtractor();
    $result = $reader->extractFile($snapshot['files']['Example.php'], $root, $snapshot['identities']['Example.php'], 134217728);
    checkSourceWorker($result['state'] === 'clean' && $result['sourceBytes'] === strlen($source), 'Stable source must be read and parsed in the worker.');
    checkSourceWorker(!file_exists($canary), 'The source worker must never execute scanned PHP.');
    $limited = $reader->extractFile($path, $root, $snapshot['identities']['Example.php'], 1);
    checkSourceWorker($limited['state'] === 'skipped' && $limited['reason'] === 'source-limit', 'The remaining scan byte limit must be enforced.');
    $timeout = (new IsolatedExtractor(0.000001))->extractFile($path, $root, $snapshot['identities']['Example.php'], 134217728);
    checkSourceWorker($timeout['state'] === 'failed' && $timeout['reason'] === 'source-or-parser-timeout', 'The worker deadline must include source reads.');
    file_put_contents($outside, '<?php class Outside {}');
    $outsideResult = $reader->extractFile($outside, $root, $snapshot['identities']['Example.php'], 134217728);
    checkSourceWorker($outsideResult['state'] === 'skipped' && $outsideResult['reason'] === 'file-unavailable', 'An outside file must not be read.');
    file_put_contents($path, '<?php class ChangedExample {}');
    $changed = $reader->extractFile($path, $root, $snapshot['identities']['Example.php'], 134217728);
    checkSourceWorker($changed['state'] === 'skipped' && $changed['reason'] === 'file-changed', 'A changed source must not be parsed.');
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_mkfifo')) {
        unlink($path);
        checkSourceWorker(posix_mkfifo($path, 0600), 'Could not create the FIFO fixture.');
        $fifo = $reader->extractFile($path, $root, $snapshot['identities']['Example.php'], 134217728);
        checkSourceWorker($fifo['state'] === 'skipped' && $fifo['reason'] === 'not-regular-file', 'A replaced FIFO must be skipped by the worker.');
    }
    echo "Source worker checks passed.\n";
} finally {
    if (is_file($canary)) unlink($canary);
    if (@lstat($path) !== false) unlink($path);
    if (is_file($outside)) unlink($outside);
    rmdir($root);
}
