<?php

declare(strict_types=1);

use Unknot\Report\JsonWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? '') === '--writer') {
    $successes = 0;
    for ($i = 0; $i < 20; $i++) {
        try {
            $report = ['scan' => ['warnings' => []], 'nodes' => [['parseState' => 'clean']], 'fixture' => str_repeat($argv[3], 16384)];
            (new JsonWriter())->write($report, $argv[2], dirname(__DIR__) . '/fixtures/sample-legacy-app');
            $successes++;
        } catch (RuntimeException $error) {
            // A contested destination can fail visibly; it must not produce a partial report.
        }
    }
    echo $successes . "\n";
    exit(0);
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-output-race-' . bin2hex(random_bytes(8));
if (!mkdir($scratch)) {
    throw new RuntimeException('Could not create the concurrent output fixture.');
}
$output = $scratch . '/report.json';
file_put_contents($output, '{"fixture":"initial"}');
$workers = [];
try {
    foreach (['a', 'b'] as $letter) {
        $process = proc_open([PHP_BINARY, '-n', __FILE__, '--writer', $output, $letter], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a concurrent output writer.');
        }
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
    }
    $deadline = hrtime(true) / 1e9 + 15;
    do {
        $running = false;
        foreach ($workers as $worker) {
            $running = proc_get_status($worker['process'])['running'] || $running;
        }
        $contents = @file_get_contents($output);
        if ($contents !== false) {
            $decoded = json_decode($contents, true);
            $fixture = $decoded['fixture'] ?? null;
            if ($fixture !== 'initial' && $fixture !== str_repeat('a', 16384) && $fixture !== str_repeat('b', 16384)) {
                throw new RuntimeException('A concurrent output read observed a partial or invalid report.');
            }
        }
        usleep(1000);
    } while ($running && hrtime(true) / 1e9 < $deadline);
    if ($running) {
        throw new RuntimeException('Concurrent output writers did not finish before the deadline.');
    }
    $successes = 0;
    foreach ($workers as $worker) {
        $result = trim((string) stream_get_contents($worker['out']));
        $errors = stream_get_contents($worker['err']);
        if (!ctype_digit($result) || $errors !== '') {
            throw new RuntimeException('A concurrent writer failed unexpectedly.');
        }
        $successes += (int) $result;
    }
    if ($successes < 1) {
        throw new RuntimeException('Neither concurrent writer completed a report.');
    }
    echo "Concurrent report replacement checks passed.\n";
} finally {
    foreach ($workers as $worker) {
        fclose($worker['out']);
        fclose($worker['err']);
        if (proc_get_status($worker['process'])['running']) {
            proc_terminate($worker['process'], 9);
        }
        proc_close($worker['process']);
    }
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
