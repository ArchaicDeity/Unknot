<?php

declare(strict_types=1);

/** Verify known source relationships in reports from the documented release archives. */
$directory = $argv[1] ?? '';
if ($directory === '' || !is_dir($directory)) {
    fwrite(STDERR, "Usage: php tests/check-open-source.php <report-directory>\n");
    exit(1);
}

$cases = [
    'fastroute-context.json' => [
        'name' => 'FastRoute v1.3.0', 'count' => 31, 'nonClean' => 3,
        'from' => 'file:src/DataGenerator/CharCountBased.php',
        'to' => 'file:src/DataGenerator/RegexBasedAbstract.php', 'type' => 'extends', 'line' => 5,
    ],
    'monolog-context.json' => [
        'name' => 'Monolog 2.7.0', 'count' => 114, 'nonClean' => 0,
        'from' => 'file:src/Monolog/Handler/StreamHandler.php',
        'to' => 'file:src/Monolog/Handler/AbstractProcessingHandler.php', 'type' => 'extends', 'line' => 26,
    ],
    'phpdotenv-context.json' => [
        'name' => 'PHP dotenv v5.4.1', 'count' => 41, 'nonClean' => 0,
        'from' => 'file:src/Exception/InvalidEncodingException.php',
        'to' => 'file:src/Exception/ExceptionInterface.php', 'type' => 'implements', 'line' => 9,
    ],
];

foreach ($cases as $file => $case) {
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $file;
    $bytes = @file_get_contents($path);
    if ($bytes === false || strlen($bytes) > 20971520) {
        throw new RuntimeException('Report unavailable or too large: ' . $case['name']);
    }
    $report = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    if (($report['scan']['fileCount'] ?? null) !== $case['count']) {
        throw new RuntimeException('Unexpected file count: ' . $case['name']);
    }
    $nonClean = array_filter($report['nodes'] ?? [], static fn (array $node): bool => ($node['parseState'] ?? null) !== 'clean');
    if (count($nonClean) !== $case['nonClean']) {
        throw new RuntimeException('Unexpected skipped or failed files: ' . $case['name']);
    }
    $edges = array_filter($report['edges'] ?? [], static fn (array $edge): bool =>
        ($edge['from'] ?? null) === $case['from']
        && ($edge['to'] ?? null) === $case['to']
        && ($edge['type'] ?? null) === $case['type']
        && ($edge['line'] ?? null) === $case['line']
        && ($edge['confidence'] ?? null) === 'resolved'
    );
    if (count($edges) !== 1) {
        throw new RuntimeException('Expected source relationship was not reported: ' . $case['name']);
    }
    if ($file === 'fastroute-context.json') {
        $hackWarnings = array_filter($report['scan']['warnings'] ?? [], static fn (array $warning): bool => ($warning['code'] ?? null) === 'unsupported-hack-syntax');
        if (count($hackWarnings) !== 3) {
            throw new RuntimeException('Expected Hack fixtures were not identified: ' . $case['name']);
        }
    }
    echo $case['name'] . ": known relationship verified.\n";
}
