<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use RuntimeException;

/** Runs the parser in a separate PHP process and keeps source in memory. */
final class IsolatedExtractor
{
    private const MAX_RESPONSE_BYTES = 16777216;
    private $server = null;
    private ?string $address = null;

    public function __construct(private float $timeoutSeconds = 10.0)
    {
        if ($timeoutSeconds <= 0) {
            throw new RuntimeException('Parser timeout must be positive.');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    public function extract(string $source): array
    {
        $failure = static fn (string $reason): array => [
            'state' => 'failed', 'reason' => $reason, 'line' => null, 'declarations' => [], 'references' => [],
        ];
        if (!is_resource($this->server)) {
            $this->server = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
            if ($this->server === false) {
                return $failure('parser-isolation-unavailable');
            }
            stream_set_blocking($this->server, false);
            $this->address = stream_socket_get_name($this->server, false) ?: null;
        }
        if ($this->address === null || strlen($source) > 2097152) {
            return $failure($this->address === null ? 'parser-isolation-unavailable' : 'source-limit');
        }
        $token = bin2hex(random_bytes(32));
        $environment = [];
        foreach (['PATH', 'SystemRoot', 'WINDIR', 'TMP', 'TEMP'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $environment[$key] = $value;
            }
        }
        $environment['UNKNOT_WORKER_ADDRESS'] = $this->address;
        $environment['UNKNOT_WORKER_TOKEN'] = $token;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
        $worker = dirname(__DIR__, 2) . '/bin/parser-worker';
        // ⟡ Ignore inherited ini files: extensions can execute before prepend/append overrides apply.
        $command = [PHP_BINARY, '-n', '-d', 'memory_limit=128M', '-d', 'display_errors=0', '-d', 'log_errors=0'];
        $ffi = dirname(PHP_BINARY) . '/ext/php_ffi.dll';
        if (PHP_OS_FAMILY === 'Windows' && is_file($ffi)) {
            $command = array_merge($command, ['-d', 'extension=' . $ffi, '-d', 'ffi.enable=true']);
        }
        $command[] = $worker;
        $process = @proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return $failure('parser-worker-start-failed');
        }

        $client = null;
        $deadline = hrtime(true) / 1e9 + $this->timeoutSeconds;
        $request = pack('N', strlen($source)) . $source;
        $sent = 0;
        $response = '';
        $responseLength = null;
        try {
            $authentication = (new WorkerAuthenticator())->accept($this->server, $process, $token, $deadline);
            $client = $authentication['client'];
            if ($client === null) {
                return $failure($authentication['reason']);
            }
            while (hrtime(true) / 1e9 < $deadline) {
                $read = [$client];
                $write = $sent < strlen($request) ? [$client] : [];
                $except = null;
                if (@stream_select($read, $write, $except, 0, 50000) === false) {
                    return $failure('parser-transport-failed');
                }
                if ($read !== []) {
                    $chunk = @fread($client, 65536);
                    if ($chunk === false || ($chunk === '' && feof($client))) {
                        return $failure('parser-worker-failed');
                    }
                    $response .= $chunk;
                    if ($responseLength === null && strlen($response) >= 4) {
                        $responseLength = unpack('Nlength', substr($response, 0, 4))['length'];
                        $response = substr($response, 4);
                        if ($responseLength < 1 || $responseLength > self::MAX_RESPONSE_BYTES) {
                            return $failure('parser-response-limit');
                        }
                    }
                    if ($responseLength !== null && strlen($response) >= $responseLength) {
                        return $this->decode($response, $responseLength, $source) ?? $failure('parser-invalid-response');
                    }
                }
                if ($write !== []) {
                    $written = @fwrite($client, substr($request, $sent, 65536));
                    if ($written === false) {
                        return $failure('parser-transport-failed');
                    }
                    $sent += $written;
                }
            }
            return $failure('parser-timeout');
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }

    private function decode(string $response, int $expectedLength, string $source): ?array
    {
        return (new WorkerResponse())->decode($response, $expectedLength, $source);
    }
}
