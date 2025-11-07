<?php

declare(strict_types=1);

use Unknot\Report\JsonWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_setrlimit') || !defined('POSIX_RLIMIT_FSIZE')) {
    echo "Report interruption check skipped: Linux POSIX file-size limits are unavailable.\n";
    exit(0);
}

if (($argv[1] ?? '') === '--writer') {
    if (!posix_setrlimit(POSIX_RLIMIT_FSIZE, 1024, 1024)) {
        exit(3);
    }
    $report = ['scan' => ['warnings' => []], 'nodes' => [['parseState' => 'clean', 'fixture' => str_repeat('x', 1048576)]]];
    (new JsonWriter())->write($report, $argv[2], dirname(__DIR__) . '/fixtures/sample-legacy-app');
    exit(0);
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-interrupted-' . bin2hex(random_bytes(8));
if (!mkdir($scratch)) {
    throw new RuntimeException('Could not create the interruption fixture.');
}
$output = $scratch . '/report.json';
file_put_contents($output, 'previous report');
try {
    if (!is_dir(dirname(__DIR__) . '/fixtures/sample-legacy-app')) {
        throw new RuntimeException('The scan-root fixture is missing.');
    }
    $process = proc_open([PHP_BINARY, '-n', __FILE__, '--writer', $output], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the interrupted report writer.');
    }
    $status = proc_close($process);
    if ($status === 0 || $status === 3 || file_get_contents($output) !== 'previous report') {
        throw new RuntimeException('An interrupted write must preserve the previous report.');
    }
    $temporaryFiles = glob($scratch . '/.unknot-*');
    if (count($temporaryFiles) !== 1 || filesize($temporaryFiles[0]) !== 1024) {
        throw new RuntimeException('The writer did not reach the simulated file-size interruption.');
    }
    foreach ($temporaryFiles as $temporary) {
        if (!is_file($temporary) || is_link($temporary) || (fileperms($temporary) & 0077) !== 0) {
            throw new RuntimeException('An interrupted temporary report must be a private regular file.');
        }
    }
    echo "Interrupted report write check passed.\n";
} finally {
    foreach (glob($scratch . '/.unknot-*') as $temporary) {
        if (is_file($temporary) && !is_link($temporary)) {
            unlink($temporary);
        }
    }
    if (is_file($output)) {
        unlink($output);
    }
    rmdir($scratch);
}
