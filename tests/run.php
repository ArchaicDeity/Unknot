<?php

declare(strict_types=1);

use LegacyLens\Report\JsonWriter;
use LegacyLens\Scanner\Extractor;
use LegacyLens\Scanner\IsolatedExtractor;
use LegacyLens\Scanner\FileDiscovery;
use LegacyLens\Scanner\Scanner;

require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = dirname(__DIR__) . '/fixtures/sample-legacy-app';
$canary = $fixture . '/CANARY_EXECUTED';
check(!file_exists($canary), 'Remove the existing fixture canary before running this test.');
$scanner = new Scanner(new FileDiscovery(), new IsolatedExtractor());
$report = $scanner->scan($fixture);
check($report === $scanner->scan($fixture), 'Repeated scans must be deterministic.');
check($report['scan']['fileCount'] === 3, 'Expected all fixture files.');
check(!file_exists($canary), 'Scanning must never execute the target.');

$matching = static function (array $criteria) use ($report): array {
    return array_values(array_filter($report['edges'], static function (array $edge) use ($criteria): bool {
        foreach ($criteria as $key => $value) {
            if ($edge[$key] !== $value) {
                return false;
            }
        }
        return true;
    }));
};
check(count($matching(['from' => 'file:Orders.php', 'to' => 'file:Storage.php', 'type' => 'instantiation', 'confidence' => 'resolved'])) === 1, 'Alias must resolve to its declaration.');
check(count($matching(['from' => 'file:Orders.php', 'to' => 'file:Storage.php', 'type' => 'include', 'confidence' => 'heuristic'])) === 1, 'A source-relative include must be marked heuristic.');
check(count($matching(['from' => 'file:Orders.php', 'to' => null, 'type' => 'include', 'reasonCode' => 'dynamic-expression'])) === 1, 'Dynamic includes must remain unresolved.');
check(count($matching(['from' => 'file:Conditional.php', 'to' => null, 'reasonCode' => 'conditional-symbol'])) === 1, 'Conditional declarations must not become certain edges.');
$conditional = $matching(['from' => 'file:Conditional.php', 'reasonCode' => 'conditional-symbol'])[0];
check($conditional['target']['candidates'] === ['file:Conditional.php'], 'Unresolved conditional references must retain candidate evidence.');
check($conditional['target']['name'] === 'Demo\\ConditionalStore', 'Named references must explain the symbol under consideration.');
check($matching(['from' => 'file:Orders.php', 'type' => 'include', 'reasonCode' => 'dynamic-expression'])[0]['target']['name'] === null, 'Dynamic expressions must not leak source text.');
check((new Extractor())->extract('<?php class {')['state'] === 'failed', 'Invalid syntax must produce a parse failure.');
check((new Extractor())->extract('<?hh namespace Demo;')['reason'] === 'unsupported-hack-syntax', 'Hack source must be reported as unsupported.');
$syntaxCases = [
    '<?php class Legacy { public function values() { return array(1, 2); } }',
    '<?php class Modern { public function __construct(public readonly string $name) {} } enum Status: string { case Ready = "ready"; }',
];
foreach ($syntaxCases as $source) {
    check((new Extractor())->extract($source)['state'] === 'clean', 'Representative legacy and PHP 8.1 syntax must parse.');
}
$excludedReport = $scanner->scan($fixture, ['Storage.php']);
check($excludedReport['scan']['fileCount'] === 2, 'Explicit exclusions must remove files from discovery.');
check(count(array_filter($excludedReport['edges'], static fn (array $edge): bool => $edge['type'] === 'instantiation' && $edge['reasonCode'] === 'symbol-not-found')) === 1, 'An excluded declaration must not resolve.');
foreach (['https://example.invalid/repo', '\\\\server\\share'] as $remoteRoot) {
    try {
        $scanner->scan($remoteRoot);
        throw new RuntimeException('A remote scan root must be rejected.');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'The scan root must be a local directory.', 'Unexpected remote-root failure.');
    }
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'legacylens-' . bin2hex(random_bytes(8));
check(mkdir($scratch), 'Could not create the temporary fixture.');
$scratchFiles = ['first.php', 'second.php', 'reference.php', 'broken.php', 'oversized.php', 'include.php'];
try {
    file_put_contents($scratch . '/first.php', '<?php namespace Sample; class Duplicate {}');
    file_put_contents($scratch . '/second.php', '<?php namespace Sample; class Duplicate {}');
    file_put_contents($scratch . '/reference.php', '<?php namespace Sample; new Duplicate(); new Duplicate();');
    file_put_contents($scratch . '/broken.php', '<?php /* PRIVATE_SOURCE_MARKER */ class {');
    file_put_contents($scratch . '/oversized.php', str_repeat('a', 2097153));
    file_put_contents($scratch . '/include.php', "<?php include '../outside.php'; include '/secret/private.php';");
    $partial = $scanner->scan($scratch);
    check($partial['scan']['fileCount'] === 6, 'One malformed or oversized file must not stop the scan.');
    check(count(array_filter($partial['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'parse-error')) === 1, 'Malformed files must be reported.');
    check(count(array_filter($partial['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'source-limit')) === 1, 'Oversized files must be reported.');
    check(!str_contains(json_encode($partial, JSON_THROW_ON_ERROR), 'PRIVATE_SOURCE_MARKER'), 'Diagnostics must not leak source text.');
    check(!str_contains(json_encode($partial, JSON_THROW_ON_ERROR), '/secret/private.php'), 'Include paths must not leak raw source values.');
    check(count(array_filter($partial['edges'], static fn (array $edge): bool => $edge['type'] === 'include' && $edge['to'] === null)) === 2, 'Includes outside the root must remain unresolved.');
    $ambiguous = array_values(array_filter($partial['edges'], static fn (array $edge): bool => $edge['reasonCode'] === 'ambiguous-symbol' && $edge['to'] === null));
    check(count($ambiguous) === 2, 'Duplicate declarations must not select a target.');
    check($ambiguous[0]['id'] !== $ambiguous[1]['id'], 'References on the same line must have distinct evidence IDs.');
    check($ambiguous[0]['target']['candidates'] === ['file:first.php', 'file:second.php'], 'Ambiguous candidates must remain available in deterministic order.');
} finally {
    foreach ($scratchFiles as $scratchFile) {
        if (is_file($scratch . '/' . $scratchFile)) {
            unlink($scratch . '/' . $scratchFile);
        }
    }
    rmdir($scratch);
}

$output = tempnam(sys_get_temp_dir(), 'legacylens-test-');
check($output !== false, 'Could not allocate a test report.');
try {
    file_put_contents($output, 'previous report');
    try {
        (new JsonWriter())->write(['invalid' => "\xFF"], $output, $fixture);
        throw new RuntimeException('Invalid JSON input must fail.');
    } catch (JsonException $error) {
        check(file_get_contents($output) === 'previous report', 'Failed encoding must preserve the prior output.');
    }
    (new JsonWriter())->write($report, $output, $fixture);
    check(json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR) === $report, 'JSON report must round-trip.');
} finally {
    unlink($output);
}

try {
    (new JsonWriter())->write($report, $fixture . '/report.json', $fixture);
    throw new RuntimeException('Writing inside the scan root must be rejected.');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'The output directory must exist outside the scan root.', 'Unexpected output-path failure.');
}

echo "Scanner checks passed.\n";
