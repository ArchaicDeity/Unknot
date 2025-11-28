<?php

declare(strict_types=1);

use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;
use Unknot\Scanner\SourceReader;
use Unknot\Report\JsonWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_mkfifo')) {
    echo "Special-file replacement check skipped: Linux POSIX FIFO support is unavailable.\n";
    exit(0);
}
if (($argv[1] ?? '') === '--read') {
    $identity = json_decode($argv[4], true, 16, JSON_THROW_ON_ERROR);
    $bytes = 0;
    $result = (new SourceReader(new FileDiscovery()))->read($argv[2], $argv[3], $bytes, $identity);
    echo $result['reason'] ?? 'accepted';
    exit(0);
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-special-' . bin2hex(random_bytes(8));
$root = $scratch . '/input';
if (!mkdir($scratch) || !mkdir($root)) {
    throw new RuntimeException('Could not create the special-file fixture.');
}
$source = $root . '/Example.php';
$process = null;
$pipes = [];
try {
    file_put_contents($source, '<?php class Example {}');
    $root = realpath($root);
    $snapshot = (new FileDiscovery())->discover($root);
    if (!unlink($source) || !posix_mkfifo($source, 0600)) {
        throw new RuntimeException('Could not replace the source with a FIFO.');
    }
    $identity = json_encode($snapshot['identities']['Example.php'], JSON_THROW_ON_ERROR);
    $process = proc_open([PHP_BINARY, '-n', __FILE__, '--read', $source, $root, $identity],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the special-file reader.');
    }
    $deadline = hrtime(true) / 1e9 + 5;
    do {
        $running = proc_get_status($process)['running'];
        if ($running) {
            usleep(10000);
        }
    } while ($running && hrtime(true) / 1e9 < $deadline);
    if ($running) {
        throw new RuntimeException('Opening a replaced FIFO must not block the reader.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    if ($output !== 'not-regular-file' || $error !== '') {
        throw new RuntimeException('A FIFO replacement must be reported as a nonregular file.');
    }
    $report = (new Scanner(new FileDiscovery(), new IsolatedExtractor()))->scan($root);
    $warnings = array_filter($report['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'not-regular-file');
    if (count($warnings) !== 1 || $report['scan']['fileCount'] !== 0) {
        throw new RuntimeException('Discovery must report a source-shaped FIFO as incomplete.');
    }
    $outputPath = $scratch . '/report.json';
    file_put_contents($outputPath, 'previous report');
    try {
        (new JsonWriter())->write($report, $outputPath, $root);
        throw new RuntimeException('An incomplete scan must not replace an existing report.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'previous report was preserved') || file_get_contents($outputPath) !== 'previous report') {
            throw $error;
        }
    }
    echo "Special-file replacement check passed.\n";
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
    if (@lstat($source) !== false) {
        unlink($source);
    }
    rmdir($root);
    if (is_file($scratch . '/report.json')) {
        unlink($scratch . '/report.json');
    }
    rmdir($scratch);
}
