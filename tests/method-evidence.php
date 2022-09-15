<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LegacyLens\Scanner\FileDiscovery;
use LegacyLens\Scanner\Extractor;
use LegacyLens\Scanner\Scanner;

function checkEvidence(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$fixture = dirname(__DIR__) . '/fixtures/method-evidence';
$root = sys_get_temp_dir() . '/legacylens-method-' . bin2hex(random_bytes(16));
checkEvidence(mkdir($root, 0700), 'Could not create a private fixture directory.');
try {
    checkEvidence(copy($fixture . '/Methods.php', $root . '/Methods.php'), 'Could not copy the valid fixture.');
    checkEvidence(copy($fixture . '/Broken.php.fixture', $root . '/Broken.php'), 'Could not copy the invalid fixture.');
    $report = (new Scanner(new FileDiscovery(), new Extractor()))->scan($root);
    checkEvidence(!file_exists($root . '/CANARY_EXECUTED'), 'The scanned source was executed; the canary remains in ' . $root);
    $extension = $report['extensions']['legacyMethodEvidence'];
    checkEvidence($report['schemaVersion'] === 0 && $extension['version'] === 1, 'The optional schema-0 extension is missing.');
    $files = [];
    foreach ($extension['files'] as $entry) $files[$entry['fileId']] = $entry;
    checkEvidence($files['file:Broken.php']['coverage'] === 'unavailable' && $files['file:Broken.php']['calls'] === [], 'Failed files must not claim coverage.');
    $clean = $files['file:Methods.php'];
    checkEvidence($clean['coverage'] === 'available' && count($clean['methods']) === 2 && count($clean['calls']) === 2, 'Unexpected method or call counts.');
    checkEvidence($clean['calls'][0]['reason'] === 'static-target-unresolved' && $clean['calls'][1]['reason'] === 'dynamic-method', 'Unsupported call targets must remain unresolved.');
    foreach ($clean['calls'] as $call) {
        checkEvidence($call['target'] === null && $call['confidence'] === 'unresolved' && !isset($call['startOffset']), 'Unconfirmed targets or private offsets leaked.');
    }
} finally {
    foreach (['Methods.php', 'Broken.php'] as $name) {
        if (is_file($root . '/' . $name)) unlink($root . '/' . $name);
    }
    if (!file_exists($root . '/CANARY_EXECUTED')) rmdir($root);
}
echo "Legacy method evidence scanner checks passed.\n";
