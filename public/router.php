<?php

declare(strict_types=1);

use Unknot\Report\ReportReader;
use Unknot\Report\Hotspots;

require dirname(__DIR__) . '/vendor/autoload.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$port = getenv('UNKNOT_PORT');
$host = '127.0.0.1:' . $port;
$origin = 'http://' . $host;
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$requestHost = $_SERVER['HTTP_HOST'] ?? '';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$publicNavigation = $method === 'GET' && $path === '/' && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? null) === 'navigate';
if (!is_string($port) || !preg_match('/^[0-9]{4,5}$/', $port) || $requestHost !== $host
    || ($requestOrigin !== null && $requestOrigin !== $origin)
    || ($fetchSite !== null && !in_array($fetchSite, ['same-origin', 'none'], true) && !$publicNavigation)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($method !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed');
}
$assets = ['/' => ['index.html', 'text/html; charset=utf-8'], '/assets/style.css' => ['assets/style.css', 'text/css; charset=utf-8'],
    '/assets/app.js' => ['assets/app.js', 'text/javascript; charset=utf-8'],
    '/assets/report.js' => ['assets/report.js', 'text/javascript; charset=utf-8'],
    '/assets/graph.js' => ['assets/graph.js', 'text/javascript; charset=utf-8']];
if (isset($assets[$path])) {
    header('Content-Type: ' . $assets[$path][1]);
    readfile(__DIR__ . '/' . $assets[$path][0]);
    exit;
}
if ($path === '/api/health') {
    header('Content-Type: application/json');
    $startupId = getenv('UNKNOT_STARTUP_ID');
    exit(json_encode(['status' => 'ok', 'startupId' => is_string($startupId) ? $startupId : null], JSON_THROW_ON_ERROR));
}
if ($path !== '/api/report' && $path !== '/api/hotspots' && !str_starts_with((string) $path, '/api/files/')) {
    http_response_code(404);
    exit('Not found');
}
$token = getenv('UNKNOT_CAPABILITY');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)
    || !is_string($authorization) || !hash_equals('Bearer ' . $token, $authorization)) {
    http_response_code(401);
    exit('Unauthorized');
}
try {
    $report = (new ReportReader())->read((string) getenv('UNKNOT_REPORT'));
    header('Content-Type: application/json; charset=utf-8');
    if ($path === '/api/hotspots') {
        $result = (new Hotspots())->calculate($report);
    } elseif (str_starts_with((string) $path, '/api/files/')) {
        $id = rawurldecode(substr($path, strlen('/api/files/')));
        $matches = array_values(array_filter($report['nodes'], static fn (array $node): bool => $node['id'] === $id));
        if (strlen($id) > 4101 || count($matches) !== 1) {
            http_response_code(404);
            exit('{"error":"File not found"}');
        }
        $result = ['file' => $matches[0],
            'references' => array_values(array_filter($report['edges'], static fn (array $edge): bool => $edge['from'] === $id || $edge['to'] === $id)),
            'findings' => array_values(array_filter($report['findings'] ?? [], static fn (array $finding): bool => $finding['fileId'] === $id))];
    } else {
        $result = $report;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    exit('Report unavailable');
}
