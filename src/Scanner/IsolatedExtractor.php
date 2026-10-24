<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use RuntimeException;

/** Runs the parser in a separate PHP process and keeps source in memory. */
final class IsolatedExtractor
{
    private const MAX_RESPONSE_BYTES = 16777216;
    private const MAX_FILE_RESPONSE_BYTES = 20971520;
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
        return $this->exchange($source, false, 0);
    }

    /** Reads and parses a file inside the same deadline-bound worker. */
    public function extractFile(string $path, string $root, array|false $identity, int $remainingBytes): array
    {
        try {
            $request = json_encode(['path' => $path, 'root' => $root, 'identity' => $identity,
                'remainingBytes' => $remainingBytes], JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return ['state' => 'skipped', 'reason' => 'source-path-encoding', 'line' => null,
                'declarations' => [], 'references' => []];
        }
        return $this->exchange($request, true, $remainingBytes);
    }

    private function exchange(string $source, bool $fileMode, int $remainingBytes): array
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
        if ($fileMode) {
            $environment['UNKNOT_WORKER_MODE'] = 'file';
        }
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
                $reason = $authentication['reason'];
                return $failure($fileMode && $reason === 'parser-timeout' ? 'source-or-parser-timeout' : $reason);
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
                        $limit = $fileMode ? self::MAX_FILE_RESPONSE_BYTES : self::MAX_RESPONSE_BYTES;
                        if ($responseLength < 1 || $responseLength > $limit) {
                            return $failure('parser-response-limit');
                        }
                    }
                    if ($responseLength !== null && strlen($response) >= $responseLength) {
                        if (!$fileMode) {
                            return $this->decode($response, $responseLength, $source) ?? $failure('parser-invalid-response');
                        }
                        return $this->decodeFileResponse($response, $responseLength, $remainingBytes) ?? $failure('parser-invalid-response');
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
            return $failure($fileMode ? 'source-or-parser-timeout' : 'parser-timeout');
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

    private function decodeFileResponse(string $response, int $expectedLength, int $remainingBytes): ?array
    {
        if (strlen($response) !== $expectedLength || $remainingBytes < 0) {
            return null;
        }
        try {
            $envelope = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($envelope)) return null;
            if (isset($envelope['readFailure'])) {
                if (!in_array($envelope['readFailure'], ['file-unavailable', 'file-changed', 'not-regular-file',
                    'file-unreadable', 'source-limit'], true)) return null;
                return ['state' => 'skipped', 'reason' => $envelope['readFailure'], 'line' => null,
                    'declarations' => [], 'references' => []];
            }
            if (!is_string($envelope['source'] ?? null) || !is_array($envelope['result'] ?? null)) return null;
            $source = base64_decode($envelope['source'], true);
            if ($source === false || strlen($source) > 2097152 || strlen($source) > $remainingBytes) return null;
            $resultJson = json_encode($envelope['result'], JSON_THROW_ON_ERROR);
            if (strlen($resultJson) > self::MAX_RESPONSE_BYTES) return null;
            $result = (new WorkerResponse())->decode($resultJson, strlen($resultJson), $source);
            if ($result === null) return null;
            $result['sourceBytes'] = strlen($source);
            // Bind future provenance to the exact bytes whose parser locations were validated.
            $result['sourceSha256'] = hash('sha256', $source);
            return $result;
        } catch (\JsonException $error) {
            return null;
        }
    }

}
