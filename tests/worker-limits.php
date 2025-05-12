<?php

declare(strict_types=1);

use Unknot\Scanner\WorkerAuthenticator;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!in_array(PHP_OS_FAMILY, ['Linux', 'Windows'], true)) {
    echo "Worker limit checks skipped: unsupported platform.\n";
    exit(0);
}

function startLimitWorker(bool $disableLimit): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    if ($server === false) {
        throw new RuntimeException('Could not create a worker test listener.');
    }
    $token = bin2hex(random_bytes(32));
    $environment = ['UNKNOT_WORKER_ADDRESS' => stream_socket_get_name($server, false), 'UNKNOT_WORKER_TOKEN' => $token];
    foreach (['PATH', 'SystemRoot', 'WINDIR', 'TMP', 'TEMP'] as $name) {
        if (getenv($name) !== false) {
            $environment[$name] = getenv($name);
        }
    }
    $command = [PHP_BINARY, '-n', '-d', 'memory_limit=128M', '-d', 'display_errors=0', '-d', 'log_errors=0'];
    if (PHP_OS_FAMILY === 'Windows') {
        $command = array_merge($command, ['-d', 'extension=' . dirname(PHP_BINARY) . '/ext/php_ffi.dll', '-d', 'ffi.enable=' . ($disableLimit ? 'false' : 'true')]);
    } elseif ($disableLimit) {
        $command = array_merge($command, ['-d', 'disable_functions=posix_setrlimit']);
    }
    $command[] = dirname(__DIR__) . '/bin/parser-worker';
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        fclose($server);
        throw new RuntimeException('Could not start a worker.');
    }
    stream_set_blocking($server, false);
    return [$server, $process, $token];
}

function closeLimitWorker($server, $process, $client): void
{
    if (is_resource($client)) {
        fclose($client);
    }
    fclose($server);
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
}

function readLimitBytes($client, int $length): string
{
    $bytes = '';
    while (strlen($bytes) < $length && !feof($client)) {
        $chunk = fread($client, $length - strlen($bytes));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $bytes .= $chunk;
    }
    return $bytes;
}

[$server, $process, $token] = startLimitWorker(false);
$client = null;
try {
    $result = (new WorkerAuthenticator())->accept($server, $process, $token, hrtime(true) / 1e9 + 10);
    $client = $result['client'];
    if (!is_resource($client)) {
        throw new RuntimeException('The real worker could not establish its operating-system memory limit.');
    }
    if (PHP_OS_FAMILY === 'Linux') {
        $pid = proc_get_status($process)['pid'];
        $limits = file_get_contents('/proc/' . $pid . '/limits');
        if (!is_string($limits) || preg_match('/^Max address space\s+(\d+)\s+(\d+)\s+bytes/m', $limits, $matches) !== 1
            || (int) $matches[1] > 268435456 || (int) $matches[1] !== (int) $matches[2]) {
            throw new RuntimeException('The real worker address-space limit was not verified.');
        }
    }
    stream_set_blocking($client, true);
    stream_set_timeout($client, 10);
    $source = '<?php class LimitFixture {}';
    fwrite($client, pack('N', strlen($source)) . $source);
    $header = readLimitBytes($client, 4);
    if (strlen($header) !== 4) {
        throw new RuntimeException('The limited worker did not return a report.');
    }
    $length = unpack('Nlength', $header)['length'];
    $json = readLimitBytes($client, $length);
    if ((json_decode($json, true)['state'] ?? null) !== 'clean') {
        throw new RuntimeException('The limited worker failed a normal parse.');
    }
} finally {
    closeLimitWorker($server, $process, $client);
}

[$server, $process, $token] = startLimitWorker(true);
try {
    $result = (new WorkerAuthenticator())->accept($server, $process, $token, hrtime(true) / 1e9 + 10);
    if ($result['client'] !== null || $result['reason'] !== 'parser-resource-limit-unavailable') {
        throw new RuntimeException('An unavailable OS limit must be reported before sending source.');
    }
} finally {
    closeLimitWorker($server, $process, null);
}

$command = [PHP_BINARY, '-n', '-d', 'memory_limit=-1'];
if (PHP_OS_FAMILY === 'Windows') {
    $command = array_merge($command, ['-d', 'extension=' . dirname(PHP_BINARY) . '/ext/php_ffi.dll', '-d', 'ffi.enable=true']);
}
$command[] = __DIR__ . '/worker-memory-probe.php';
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the bounded allocation probe.');
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
if (PHP_OS_FAMILY === 'Linux') {
    if ($status !== 255 || !str_contains($output . $error, 'OS limit applied') || !str_contains($output . $error, 'Out of memory')) {
        throw new RuntimeException('The Linux allocation probe did not show the expected bounded failure.');
    }
    $process = proc_open([PHP_BINARY, '-n', '-d', 'memory_limit=128M', __DIR__ . '/worker-memory-probe.php', '--inherited'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the inherited-limit probe.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !str_contains($output, 'Inherited limit preserved.')) {
        throw new RuntimeException('The inherited-limit probe failed.');
    }
} elseif ($status !== 0 || !str_contains($output, 'Windows native allocation rejected')) {
    throw new RuntimeException('The Windows allocation probe did not verify the limit.');
}
echo "Worker operating-system memory-limit checks passed.\n";
