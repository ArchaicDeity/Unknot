<?php

declare(strict_types=1);

use LegacyLens\Scanner\IsolatedExtractor;
use LegacyLens\Scanner\WorkerAuthenticator;

require dirname(__DIR__) . '/vendor/autoload.php';

$extractor = new IsolatedExtractor(1.5);
if ($extractor->extract('<?php class Warmup {}')['state'] !== 'clean') {
    throw new RuntimeException('Could not start the worker listener.');
}
$address = (new ReflectionProperty(IsolatedExtractor::class, 'address'))->getValue($extractor);
$clients = [];
try {
    for ($i = 0; $i < 12; $i++) {
        $client = stream_socket_client('tcp://' . $address, $number, $message, 2);
        if ($client === false) {
            throw new RuntimeException('Could not create the competing connection fixture.');
        }
        if ($i === 0) {
            // A complete but invalid capability must not receive source.
            fwrite($client, str_repeat('0', 64) . "\n\x01");
        } elseif ($i === 1) {
            // A client may disconnect in the middle of authentication.
            fwrite($client, 'partial');
            fclose($client);
            continue;
        } else {
            fwrite($client, 'partial');
        }
        $clients[] = $client;
    }
    $result = $extractor->extract('<?php class PrivateSourceProbe {}');
    if ($result['state'] !== 'clean') {
        throw new RuntimeException('Stalled clients prevented the authenticated worker from parsing.');
    }
    foreach ($clients as $client) {
        stream_set_blocking($client, false);
        $data = @stream_get_contents($client);
        if ($data !== '' && $data !== false) {
            throw new RuntimeException('An unauthenticated connection received data.');
        }
    }
    // A connection left over from one extraction must not authenticate the next.
    $stale = stream_socket_client('tcp://' . $address, $number, $message, 2);
    if ($stale === false) {
        throw new RuntimeException('Could not create a stale connection fixture.');
    }
    $clients[] = $stale;
    fwrite($stale, str_repeat('f', 64) . "\n\x01");
    if ($extractor->extract('<?php class NextPrivateSource {}')['state'] !== 'clean') {
        throw new RuntimeException('A stale capability prevented the next worker from parsing.');
    }
    stream_set_blocking($stale, false);
    $data = @stream_get_contents($stale);
    if ($data !== '' && $data !== false) {
        throw new RuntimeException('A stale connection received data.');
    }
} finally {
    foreach ($clients as $client) {
        fclose($client);
    }
}

// With no genuine worker connection, partial handshakes must expire and the
// parent must reach its own deadline without giving any client source.
$server = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
if ($server === false) {
    throw new RuntimeException('Could not create a timeout listener.');
}
stream_set_blocking($server, false);
$address = stream_socket_get_name($server, false);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open([PHP_BINARY, '-n', '-r', 'usleep(3000000);'], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($process)) {
    fclose($server);
    throw new RuntimeException('Could not start the timeout fixture.');
}
$clients = [];
try {
    for ($i = 0; $i < 12; $i++) {
        $client = stream_socket_client('tcp://' . $address, $number, $message, 2);
        if ($client === false) {
            throw new RuntimeException('Could not create a timeout client.');
        }
        fwrite($client, 'partial');
        $clients[] = $client;
    }
    $result = (new WorkerAuthenticator())->accept($server, $process, bin2hex(random_bytes(32)), hrtime(true) / 1e9 + 1.4);
    if ($result['client'] !== null || $result['reason'] !== 'parser-timeout') {
        throw new RuntimeException('Competing partial handshakes must end with a visible timeout.');
    }
    foreach ($clients as $client) {
        stream_set_blocking($client, false);
        $data = @stream_get_contents($client);
        if ($data !== '' && $data !== false) {
            throw new RuntimeException('A timed-out connection received data.');
        }
    }
} finally {
    foreach ($clients as $client) {
        fclose($client);
    }
    fclose($server);
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
}
echo "Competing worker connection checks passed.\n";
