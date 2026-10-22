<?php

declare(strict_types=1);

namespace Unknot\Web;

use RuntimeException;
use Unknot\Report\ReportReader;

final class Server
{
    public function run(string $reportPath, int $port, bool $isDemo = false): int
    {
        if ($port < 1024 || $port > 65535) {
            throw new RuntimeException('Port must be between 1024 and 65535.');
        }
        (new ReportReader())->read($reportPath);
        $path = realpath($reportPath);
        if ($path === false) {
            throw new RuntimeException('Could not resolve the report.');
        }
        $token = bin2hex(random_bytes(32));
        $environment = getenv();
        $environment['UNKNOT_REPORT'] = $path;
        $environment['UNKNOT_CAPABILITY'] = $token;
        $environment['UNKNOT_PORT'] = (string) $port;
        $startupId = bin2hex(random_bytes(16));
        $environment['UNKNOT_STARTUP_ID'] = $startupId;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__, 2) . '/public/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes,
            dirname(__DIR__, 2), $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the local server.');
        }
        try {
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                usleep(50000);
                if (!proc_get_status($process)['running']) {
                    break;
                }
                $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 0.1);
                if ($connection !== false) {
                    stream_set_timeout($connection, 1);
                    fwrite($connection, "GET /api/health HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
                    $reply = stream_get_contents($connection, 4096);
                    fclose($connection);
                    usleep(50000);
                    $ready = is_string($reply) && str_contains($reply, '"startupId":"' . $startupId . '"')
                        && proc_get_status($process)['running'];
                    break;
                }
            }
            if (!$ready) {
                throw new RuntimeException('Could not start the local server; the port may already be in use.');
            }
            $query = $isDemo ? '?demo=laravel' : '';
            fwrite(STDOUT, "Open http://127.0.0.1:{$port}/{$query}#{$token}\nKeep this URL private. Press Ctrl+C to stop.\n");
            while (proc_get_status($process)['running']) {
                usleep(250000);
            }
            return 1;
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
