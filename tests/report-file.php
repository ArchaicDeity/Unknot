<?php

declare(strict_types=1);

use Unknot\Report\JsonWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-output-' . bin2hex(random_bytes(8));
if (!mkdir($scratch)) {
    throw new RuntimeException('Could not create the report fixture directory.');
}
$output = $scratch . '/report.json';
$report = ['scan' => ['warnings' => []], 'nodes' => [['parseState' => 'clean']]];
$previousMask = umask(0);
try {
    (new JsonWriter())->write($report, $output, dirname(__DIR__) . '/fixtures/sample-legacy-app');
    if (umask() !== 0 || json_decode((string) file_get_contents($output), true, 64, JSON_THROW_ON_ERROR) !== $report) {
        throw new RuntimeException('The report must round-trip and the process umask must be restored.');
    }
    if (PHP_OS_FAMILY !== 'Windows' && (fileperms($output) & 0077) !== 0) {
        throw new RuntimeException('The report must not grant group or other permissions on POSIX.');
    }
    if (count(glob($scratch . '/.unknot-*')) !== 0) {
        throw new RuntimeException('A successful report must not leave a temporary file.');
    }
    $original = file_get_contents($output);
    $tooLarge = $report;
    $tooLarge['padding'] = str_repeat('x', 16777216);
    $rejected = false;
    try {
        (new JsonWriter())->write($tooLarge, $output, dirname(__DIR__) . '/fixtures/sample-legacy-app');
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), '16 MiB');
    }
    if (!$rejected || file_get_contents($output) !== $original || count(glob($scratch . '/.unknot-*')) !== 0) {
        throw new RuntimeException('An oversized report must be rejected without replacing the existing file.');
    }
    echo "Report file checks passed.\n";
} finally {
    umask($previousMask);
    if (is_file($output)) {
        unlink($output);
    }
    rmdir($scratch);
}
