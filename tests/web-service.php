<?php

declare(strict_types=1);

use Unknot\Report\Hotspots;
use Unknot\Report\ReportReader;
use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkWeb(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$report = (new Scanner(new FileDiscovery(), new IsolatedExtractor()))->scan($root . '/fixtures/sample-legacy-app');
(new ReportReader())->validate($report);
$bad = $report;
$bad['edges'][0]['confidence'] = 'invented';
try {
    (new ReportReader())->validate($bad);
    throw new RuntimeException('Invalid confidence was accepted.');
} catch (RuntimeException $error) {
    checkWeb($error->getMessage() !== 'Invalid confidence was accepted.', 'Invalid confidence was accepted.');
}
$bad = $report;
$bad['edges'][0]['from'] = ['not-a-file'];
try {
    (new ReportReader())->validate($bad);
    throw new RuntimeException('Invalid file ID was accepted.');
} catch (RuntimeException $error) {
    checkWeb($error->getMessage() !== 'Invalid file ID was accepted.', 'Invalid file ID was accepted.');
}
$rows = (new Hotspots())->calculate($report);
checkWeb(count($rows) === count($report['nodes']) && $rows[0]['score'] >= $rows[count($rows) - 1]['score'], 'Hotspot ranking must cover the report and be sorted.');

$oversized = tempnam(sys_get_temp_dir(), 'unknot-large-');
checkWeb($oversized !== false, 'Could not create size-limit fixture.');
try {
    $file = fopen($oversized, 'wb');
    checkWeb($file !== false && ftruncate($file, 16777217), 'Could not prepare size-limit fixture.');
    fclose($file);
    try {
        (new ReportReader())->read($oversized);
        throw new RuntimeException('Oversized report was accepted.');
    } catch (RuntimeException $error) {
        checkWeb($error->getMessage() !== 'Oversized report was accepted.', 'Oversized report was accepted.');
    }
} finally {
    unlink($oversized);
}

$temporary = tempnam(sys_get_temp_dir(), 'unknot-web-');
checkWeb($temporary !== false && file_put_contents($temporary, json_encode($report, JSON_THROW_ON_ERROR)) !== false, 'Could not create test report.');
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
checkWeb($listener !== false, 'Could not allocate a test port.');
$address = stream_socket_get_name($listener, false);
$port = (int) substr(strrchr($address, ':'), 1);
fclose($listener);
$token = str_repeat('a', 64);
$environment = getenv();
$environment['UNKNOT_REPORT'] = $temporary;
$environment['UNKNOT_CAPABILITY'] = $token;
$environment['UNKNOT_PORT'] = (string) $port;
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/public/router.php'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
checkWeb(is_resource($server), 'Could not start the test server.');
try {
    $ready = false;
    for ($i = 0; $i < 60; $i++) {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if ($socket !== false) { fclose($socket); $ready = true; break; }
        usleep(50000);
    }
    checkWeb($ready, 'Test server did not start.');
    $request = static function (string $path, array $headers = [], string $method = 'GET') use ($port): array {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
        if (!$socket) throw new RuntimeException('Could not connect to test server.');
        $headers += ['Host' => '127.0.0.1:' . $port, 'Connection' => 'close'];
        $wire = $method . ' ' . $path . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) $wire .= $name . ': ' . $value . "\r\n";
        fwrite($socket, $wire . "\r\n");
        $response = stream_get_contents($socket); fclose($socket);
        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $head, $match);
        return [(int) ($match[1] ?? 0), $head, $body];
    };
    [$status, $head, $body] = $request('/');
    checkWeb($status === 200 && !str_contains($body, $report['scan']['rootLabel']) && str_contains(strtolower($head), 'content-security-policy:'), 'Public shell must not contain report data and must include CSP.');
    $styleHash = base64_encode(hash('sha256', '.__________cytoscape_container { position: relative; }', true));
    checkWeb(str_contains($head, "style-src 'self' 'sha256-{$styleHash}'") && !str_contains(strtolower($head), "'unsafe-inline'"), 'CSP must allow only the fixed graph style and reject arbitrary inline styles.');
    [$assetStatus, $assetHeaders] = $request('/assets/vendor/cytoscape.esm.min.mjs');
    checkWeb($assetStatus === 200 && str_contains(strtolower($assetHeaders), 'content-type: text/javascript'), 'Bundled graph library must be served locally as JavaScript.');
    checkWeb($request('/assets/graph-model.js')[0] === 200, 'Graph model must be available locally.');
    checkWeb($request('/assets/search.js')[0] === 200, 'Declaration search must be available locally.');
    checkWeb($request('/assets/source-check.js')[0] === 200, 'Local file comparison must be available locally.');
    checkWeb($request('/assets/overview.js')[0] === 200 && $request('/assets/overview-model.js')[0] === 200, 'Report overview must be available locally.');
    checkWeb($request('/assets/impact.js')[0] === 200 && $request('/assets/impact-model.js')[0] === 200, 'Impact analysis must be available locally.');
    checkWeb($request('/assets/graph-layout.js')[0] === 200, 'Graph layouts must be available locally.');
    checkWeb($request('/assets/graph-selection.js')[0] === 200, 'Graph selection logic must be available locally.');
    [$logoStatus, $logoHeaders] = $request('/assets/unknot-mark.svg');
    checkWeb($logoStatus === 200 && str_contains(strtolower($logoHeaders), 'content-type: image/svg+xml'), 'The Unknot mark must be served as SVG.');
    checkWeb($request('/', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate'])[0] === 200, 'A cross-site navigation may open the generic shell.');
    checkWeb($request('/api/report')[0] === 401, 'Report requires a capability.');
    checkWeb($request('/api/report', ['Authorization' => 'Bearer ' . str_repeat('b', 64)])[0] === 401, 'Wrong capability must fail.');
    $auth = ['Authorization' => 'Bearer ' . $token];
    checkWeb($request('/api/report', $auth + ['Host' => 'attacker.test:' . $port])[0] === 403, 'Untrusted Host must fail.');
    checkWeb($request('/api/report', $auth + ['Origin' => 'https://attacker.test'])[0] === 403, 'Cross-origin request must fail.');
    checkWeb($request('/api/report', $auth + ['Sec-Fetch-Site' => 'cross-site'])[0] === 403, 'Cross-site fetch must fail.');
    checkWeb($request('/api/report', $auth + ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate'])[0] === 403, 'Cross-site navigation must not reveal report data.');
    checkWeb($request('/api/report', $auth, 'POST')[0] === 405, 'Write methods must fail.');
    [$status, $head, $body] = $request('/api/report', $auth);
    checkWeb($status === 200 && json_decode($body, true, 64, JSON_THROW_ON_ERROR) === $report && str_contains(strtolower($head), 'cache-control: no-store'), 'Authorized report must round trip without caching.');
    [$status, , $body] = $request('/api/hotspots', $auth);
    checkWeb($status === 200 && json_decode($body, true, 64, JSON_THROW_ON_ERROR) === $rows, 'Hotspot endpoint must match calculated measures.');
    [$status, , $body] = $request('/api/files/' . rawurlencode($report['nodes'][0]['id']), $auth);
    checkWeb($status === 200 && json_decode($body, true, 64, JSON_THROW_ON_ERROR)['file']['id'] === $report['nodes'][0]['id'], 'File lookup must use an existing report ID.');
    checkWeb($request('/api/files/' . rawurlencode('file:../../private'), $auth)[0] === 404, 'File IDs must not read paths.');
    echo "Local report service checks passed.\n";
} finally {
    proc_terminate($server); proc_close($server); unlink($temporary);
}
