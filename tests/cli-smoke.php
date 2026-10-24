<?php

declare(strict_types=1);

function runScanner(string $root, string $output, array $options = []): array
{
    $command = [PHP_BINARY, dirname(__DIR__) . '/bin/unknot', 'scan', $root, '--output=' . $output, ...$options];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the CLI test.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-cli-' . bin2hex(random_bytes(8));
$root = $scratch . '/input';
$good = $root . '/Good.php';
$bad = $root . '/Bad.php';
$canary = $root . '/EXECUTED';
$previous = $scratch . '/previous.json';
$partial = $scratch . '/partial.json';
$fingerprinted = $scratch . '/fingerprinted.json';
if (!mkdir($scratch) || !mkdir($root)) {
    throw new RuntimeException('Could not create the CLI test directory.');
}

try {
    file_put_contents($good, '<?php file_put_contents(' . var_export($canary, true) . ', "executed"); class Good {}');
    file_put_contents($bad, '<?php class {');
    file_put_contents($previous, 'prior report');

    $existing = runScanner($root, $previous);
    if ($existing['exit'] !== 1 || !str_contains($existing['stderr'], 'previous report was preserved') || file_get_contents($previous) !== 'prior report') {
        throw new RuntimeException('An incomplete CLI scan must preserve an existing report.');
    }

    $result = runScanner($root, $partial);
    if ($result['exit'] !== 2 || !is_file($partial)) {
        throw new RuntimeException('An incomplete CLI scan must write a new partial report and return warning status.');
    }
    $report = json_decode((string) file_get_contents($partial), true, 64, JSON_THROW_ON_ERROR);
    $warnings = array_filter($report['scan']['warnings'] ?? [], static fn (array $warning): bool => ($warning['code'] ?? null) === 'parse-error');
    if (($report['scan']['fileCount'] ?? null) !== 2 || count($warnings) !== 1 || file_exists($canary)) {
        throw new RuntimeException('The CLI must report the bad file without executing the target.');
    }
    $fingerprintResult = runScanner($root, $fingerprinted, ['--source-fingerprints']);
    $fingerprintReport = json_decode((string) file_get_contents($fingerprinted), true, 64, JSON_THROW_ON_ERROR);
    $nodes = array_column($fingerprintReport['nodes'], null, 'path');
    if ($fingerprintResult['exit'] !== 2 || $fingerprintReport['schemaVersion'] !== 2
        || $nodes['Good.php']['sourceSha256'] !== hash_file('sha256', $good)
        || $nodes['Bad.php']['sourceSha256'] !== null || file_exists($canary)) {
        throw new RuntimeException('Fingerprint opt-in must record the correct scan bytes without executing the target.');
    }
    echo "CLI checks passed.\n";
} finally {
    foreach ([$good, $bad, $canary, $previous, $partial, $fingerprinted] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($root);
    rmdir($scratch);
}
