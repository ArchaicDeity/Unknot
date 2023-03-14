<?php

declare(strict_types=1);

namespace LegacyLens\Scanner;

/** Authenticates bounded, concurrent local connections before source is sent. */
final class WorkerAuthenticator
{
    private const MAX_PENDING = 8;
    private const AUTH_TIMEOUT_SECONDS = 1.0;

    /** @return array{client: resource|null, reason: string|null} */
    public function accept($server, $process, string $token, float $deadline): array
    {
        $pending = [];
        try {
            while (hrtime(true) / 1e9 < $deadline) {
                $now = hrtime(true) / 1e9;
                foreach ($pending as $id => $candidate) {
                    if ($candidate['deadline'] <= $now) {
                        fclose($candidate['stream']);
                        unset($pending[$id]);
                    }
                }
                if (!proc_get_status($process)['running']) {
                    return ['client' => null, 'reason' => 'parser-worker-failed'];
                }
                $read = [$server];
                foreach ($pending as $candidate) {
                    $read[] = $candidate['stream'];
                }
                $write = [];
                $except = null;
                if (@stream_select($read, $write, $except, 0, 50000) === false) {
                    return ['client' => null, 'reason' => 'parser-transport-failed'];
                }
                foreach ($read as $stream) {
                    if (!is_resource($stream)) {
                        continue;
                    }
                    if ($stream === $server) {
                        $incoming = @stream_socket_accept($server, 0);
                        if ($incoming === false) {
                            continue;
                        }
                        stream_set_blocking($incoming, false);
                        if (count($pending) >= self::MAX_PENDING) {
                            $oldest = array_key_first($pending);
                            fclose($pending[$oldest]['stream']);
                            unset($pending[$oldest]);
                        }
                        $pending[(int) $incoming] = [
                            'stream' => $incoming, 'authentication' => '',
                            'deadline' => hrtime(true) / 1e9 + self::AUTH_TIMEOUT_SECONDS,
                        ];
                        continue;
                    }
                    $id = (int) $stream;
                    // The oldest candidate may have been evicted while processing this readiness set.
                    if (!isset($pending[$id])) {
                        continue;
                    }
                    $chunk = @fread($stream, 65 - strlen($pending[$id]['authentication']));
                    if ($chunk === false || ($chunk === '' && feof($stream))) {
                        fclose($stream);
                        unset($pending[$id]);
                        continue;
                    }
                    $pending[$id]['authentication'] .= $chunk;
                    if (strlen($pending[$id]['authentication']) === 65) {
                        if (hash_equals($token . "\n", $pending[$id]['authentication'])) {
                            unset($pending[$id]);
                            return ['client' => $stream, 'reason' => null];
                        }
                        fclose($stream);
                        unset($pending[$id]);
                    }
                }
            }
            return ['client' => null, 'reason' => 'parser-timeout'];
        } finally {
            foreach ($pending as $candidate) {
                fclose($candidate['stream']);
            }
        }
    }
}
