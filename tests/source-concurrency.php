<?php

declare(strict_types=1);

use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\SourceReader;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-concurrent-' . bin2hex(random_bytes(8));
if (!mkdir($root)) {
    throw new RuntimeException('Could not create the concurrent source fixture.');
}
$source = $root . '/Example.php';
file_put_contents($source, '<?php class Initial {}');
$writerCode = <<<'PHP'
echo "ready\n";
fgets(STDIN);
for ($i = 0; $i < 500; $i++) {
    file_put_contents($argv[1], '<?php /* ' . str_repeat('x', 16384 + ($i % 2)) . ' */ class Example {}');
    if ($i === 0) {
        echo "writing\n";
    }
    usleep(2000);
}
PHP;
$process = proc_open([PHP_BINARY, '-n', '-r', $writerCode, $source], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($process)) {
    unlink($source);
    rmdir($root);
    throw new RuntimeException('Could not start the concurrent writer.');
}
try {
    if (fgets($pipes[1]) !== "ready\n") {
        throw new RuntimeException('The concurrent writer did not start.');
    }
    fwrite($pipes[0], "start\n");
    fclose($pipes[0]);
    if (fgets($pipes[1]) !== "writing\n") {
        throw new RuntimeException('The concurrent writer did not begin changing the file.');
    }
    $discovery = new FileDiscovery();
    $reader = new SourceReader($discovery);
    $root = realpath($root);
    $accepted = 0;
    $skipped = 0;
    for ($i = 0; $i < 100; $i++) {
        usleep(1000);
        $snapshot = $discovery->discover($root);
        if (!isset($snapshot['files']['Example.php'])) {
            $skipped++;
            continue;
        }
        $bytes = 0;
        $result = $reader->read($source, $root, $bytes, $snapshot['identities']['Example.php']);
        if ($result['source'] === null) {
            if (!in_array($result['reason'], ['file-changed', 'file-unavailable', 'file-unreadable'], true)) {
                throw new RuntimeException('Concurrent reading produced an unexpected failure.');
            }
            $skipped++;
        } else {
            $accepted++;
        }
    }
    // This is a stress exercise, not proof that metadata can detect every race.
    echo "Concurrent source exercise passed: $accepted accepted, $skipped skipped.\n";
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    unlink($source);
    rmdir($root);
}
