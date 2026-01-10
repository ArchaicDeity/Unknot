<?php

declare(strict_types=1);

use Unknot\Report\JsonWriter;
use Unknot\Scanner\Extractor;
use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;

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
foreach (['extends', 'implements', 'trait-use'] as $referenceType) {
    check(count($matching(['from' => 'file:Storage.php', 'to' => 'file:Storage.php', 'type' => $referenceType, 'confidence' => 'resolved'])) === 1, 'Named inheritance, interfaces and traits must resolve with evidence.');
}
$contextual = array_values(array_filter($report['edges'], static fn (array $edge): bool => isset($edge['target']['syntax'])));
$bySyntax = static fn (string $syntax, string $from): array => array_values(array_filter($contextual, static fn (array $edge): bool => $edge['target']['syntax'] === $syntax && $edge['from'] === $from));
check($bySyntax('self', 'file:Orders.php')[0]['to'] === 'file:Orders.php', 'self must use the named lexical class.');
check($bySyntax('parent', 'file:Orders.php')[0]['to'] === 'file:Storage.php', 'parent must use the resolved parent declaration.');
check($bySyntax('static', 'file:Orders.php')[0]['reasonCode'] === 'late-static-binding', 'Late static binding must remain unresolved.');
check($bySyntax('self', 'file:Storage.php')[0]['reasonCode'] === 'trait-context-dependent', 'self inside a trait must remain unresolved.');
check(count($matching(['from' => 'file:Orders.php', 'to' => null, 'type' => 'include', 'reasonCode' => 'dynamic-expression'])) === 1, 'Dynamic includes must remain unresolved.');
check(count($matching(['from' => 'file:Conditional.php', 'to' => null, 'reasonCode' => 'conditional-symbol'])) === 1, 'Conditional declarations must not become certain edges.');
$conditional = $matching(['from' => 'file:Conditional.php', 'reasonCode' => 'conditional-symbol'])[0];
check($conditional['target']['candidates'] === ['file:Conditional.php'], 'Unresolved conditional references must retain candidate evidence.');
check($conditional['target']['name'] === 'Demo\\ConditionalStore', 'Named references must explain the symbol under consideration.');
check($matching(['from' => 'file:Orders.php', 'type' => 'include', 'reasonCode' => 'dynamic-expression'])[0]['target']['name'] === null, 'Dynamic expressions must not leak source text.');
check((new Extractor())->extract('<?php class {')['state'] === 'failed', 'Invalid syntax must produce a parse failure.');
check((new Extractor())->extract('<?hh namespace Demo;')['reason'] === 'unsupported-hack-syntax', 'Hack source must be clearly distinguished from PHP parse failures.');
$syntaxCases = [
    '<?php class Legacy { public function values() { return array(1, 2); } }',
    '<?php class Modern { public function __construct(public readonly string $name) {} } enum Status: string { case Ready = "ready"; }',
];
foreach ($syntaxCases as $source) {
    check((new Extractor())->extract($source)['state'] === 'clean', 'Representative legacy and PHP 8.1 syntax must parse.');
}
$stressSource = '<?php $x = ' . str_repeat('(', 300000) . '1' . str_repeat(')', 300000) . ';';
$stressResult = (new IsolatedExtractor())->extract($stressSource);
check($stressResult['state'] === 'failed' && $stressResult['reason'] === 'parser-worker-failed', 'A worker memory failure must become a bounded scan result.');
$timeoutResult = (new IsolatedExtractor(0.000001))->extract('<?php class SlowFixture {}');
check($timeoutResult['state'] === 'failed' && $timeoutResult['reason'] === 'parser-timeout', 'A worker deadline must stop parsing with an explicit reason.');
$guardedExtractor = new IsolatedExtractor();
check($guardedExtractor->extract('<?php class ListenerWarmup {}')['state'] === 'clean', 'The worker listener must start.');
$addressProperty = new ReflectionProperty(IsolatedExtractor::class, 'address');
$listenerAddress = $addressProperty->getValue($guardedExtractor);
$rejected = stream_socket_client('tcp://' . $listenerAddress, $socketError, $socketMessage, 2);
check($rejected !== false, 'Could not connect the invalid-token test client.');
fwrite($rejected, str_repeat('0', 64) . "\n");
$intruder = stream_socket_client('tcp://' . $listenerAddress, $socketError, $socketMessage, 2);
check($intruder !== false, 'Could not connect the unauthenticated test client.');
fwrite($intruder, 'partial');
$guardedResult = $guardedExtractor->extract('<?php class SecretFixture {}');
stream_set_blocking($intruder, false);
$intruderData = stream_get_contents($intruder);
fclose($intruder);
stream_set_blocking($rejected, false);
$rejectedData = stream_get_contents($rejected);
fclose($rejected);
check($guardedResult['state'] === 'clean', 'A stalled unauthenticated client must not prevent the worker from parsing.');
check($intruderData === '' && $rejectedData === '', 'Unauthenticated clients must never receive source or parser results.');
check($guardedExtractor->extract($stressSource)['reason'] === 'parser-worker-failed', 'A failed worker must be reported on a reused listener.');
check($guardedExtractor->extract('<?php class AfterFailure {}')['state'] === 'clean', 'A failed worker must not poison the next file.');
unset($guardedExtractor);
$iniProbe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-ini-' . bin2hex(random_bytes(8));
check(mkdir($iniProbe), 'Could not create the PHP configuration probe.');
$probeIni = $iniProbe . '/php.ini';
$probeScript = $iniProbe . '/prepend.php';
$probeMarker = $iniProbe . '/EXECUTED';
$previousPhprc = getenv('PHPRC');
try {
    file_put_contents($probeScript, '<?php file_put_contents(' . var_export($probeMarker, true) . ', "executed");');
    file_put_contents($probeIni, 'auto_prepend_file="' . str_replace('\\', '/', $probeScript) . '"' . "\n" . 'auto_append_file="' . str_replace('\\', '/', $probeScript) . '"' . "\n" . 'disable_functions=token_get_all' . "\n");
    putenv('PHPRC=' . $probeIni);
    $probeResult = (new IsolatedExtractor())->extract('<?php class ConfigProbe {}');
    check($probeResult['state'] === 'clean', 'Worker must ignore inherited PHP configuration, including disabled parser functions.');
    check(!file_exists($probeMarker), 'Worker must not execute configured prepend or append scripts.');
} finally {
    $previousPhprc === false ? putenv('PHPRC') : putenv('PHPRC=' . $previousPhprc);
    foreach ([$probeIni, $probeScript, $probeMarker] as $probeFile) {
        if (is_file($probeFile)) {
            unlink($probeFile);
        }
    }
    rmdir($iniProbe);
}
$excludedReport = $scanner->scan($fixture, ['Storage.php']);
check($excludedReport['scan']['fileCount'] === 2, 'Explicit exclusions must remove files from discovery.');
check(count(array_filter($excludedReport['edges'], static fn (array $edge): bool => $edge['type'] === 'instantiation'
    && $edge['reasonCode'] === 'symbol-not-found' && $edge['target']['name'] === 'Demo\\Storage')) === 1, 'An excluded declaration must not resolve.');
$limitedReport = (new Scanner(new FileDiscovery(), new IsolatedExtractor(), 0.000001))->scan($fixture);
check(count(array_filter($limitedReport['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'scan-time-limit')) > 0, 'A scan deadline must report skipped files.');
foreach (['https://example.invalid/repo', '\\\\server\\share'] as $remoteRoot) {
    try {
        $scanner->scan($remoteRoot);
        throw new RuntimeException('A remote scan root must be rejected.');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'The scan root must be a local directory.', 'Unexpected remote-root failure.');
    }
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-' . bin2hex(random_bytes(8));
check(mkdir($scratch), 'Could not create the temporary fixture.');
$scratchFiles = ['first.php', 'second.php', 'reference.php', 'broken.php', 'oversized.php', 'include.php', 'nested.php', 'hack.php'];
$vendoredDirectory = $scratch . '/VeNdOr';
try {
    check(mkdir($vendoredDirectory), 'Could not create the excluded fixture directory.');
    file_put_contents($vendoredDirectory . '/dependency.php', '<?php class IgnoredDependency {}');
    file_put_contents($scratch . '/first.php', '<?php namespace Sample; class Duplicate {}');
    file_put_contents($scratch . '/second.php', '<?php namespace Sample; class Duplicate {}');
    file_put_contents($scratch . '/reference.php', '<?php namespace Sample; new Duplicate(); new Duplicate();');
    file_put_contents($scratch . '/broken.php', '<?php /* PRIVATE_SOURCE_MARKER */ class {');
    file_put_contents($scratch . '/oversized.php', str_repeat('a', 2097153));
    file_put_contents($scratch . '/include.php', "<?php include '../outside.php'; include '/secret/private.php';");
    file_put_contents($scratch . '/nested.php', $stressSource);
    file_put_contents($scratch . '/hack.php', '<?hh namespace Sample;');
    $partial = $scanner->scan($scratch);
    check($partial['scan']['fileCount'] === 8, 'One malformed, oversized or resource-exhausting file must not stop the scan.');
    check(count(array_filter($partial['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'parse-error')) === 1, 'Malformed files must be reported.');
    check(count(array_filter($partial['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'source-limit')) === 1, 'Oversized files must be reported.');
    check(count(array_filter($partial['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'parser-worker-failed')) === 1, 'A worker memory failure must be reported.');
    check(count(array_filter($partial['scan']['warnings'], static fn (array $warning): bool => $warning['code'] === 'unsupported-hack-syntax')) === 1, 'Hack source must produce an explicit unsupported-language warning.');
    check(!str_contains(json_encode($partial, JSON_THROW_ON_ERROR), 'PRIVATE_SOURCE_MARKER'), 'Diagnostics must not leak source text.');
    check(!str_contains(json_encode($partial, JSON_THROW_ON_ERROR), '/secret/private.php'), 'Include paths must not leak raw source values.');
    check(count(array_filter($partial['edges'], static fn (array $edge): bool => $edge['type'] === 'include' && $edge['to'] === null)) === 2, 'Includes outside the root must remain unresolved.');
    $ambiguous = array_values(array_filter($partial['edges'], static fn (array $edge): bool => $edge['reasonCode'] === 'ambiguous-symbol' && $edge['to'] === null));
    check(count($ambiguous) === 2, 'Duplicate declarations must not select a target.');
    check($ambiguous[0]['id'] !== $ambiguous[1]['id'], 'References on the same line must have distinct evidence IDs.');
    check($ambiguous[0]['target']['candidates'] === ['file:first.php', 'file:second.php'], 'Ambiguous candidates must remain available in deterministic order.');
    $previous = tempnam(sys_get_temp_dir(), 'unknot-prior-');
    check($previous !== false, 'Could not allocate an existing report.');
    try {
        file_put_contents($previous, 'prior report');
        try {
            (new JsonWriter())->write($partial, $previous, $scratch);
            throw new RuntimeException('An incomplete scan must not replace an existing report.');
        } catch (RuntimeException $error) {
            check(str_starts_with($error->getMessage(), 'The previous report was preserved'), 'Unexpected partial-report failure.');
        }
        check(file_get_contents($previous) === 'prior report', 'Parser failure must preserve the previous report.');
    } finally {
        unlink($previous);
    }
} finally {
    foreach ($scratchFiles as $scratchFile) {
        if (is_file($scratch . '/' . $scratchFile)) {
            unlink($scratch . '/' . $scratchFile);
        }
    }
    if (is_file($vendoredDirectory . '/dependency.php')) {
        unlink($vendoredDirectory . '/dependency.php');
    }
    if (is_dir($vendoredDirectory)) {
        rmdir($vendoredDirectory);
    }
    rmdir($scratch);
}

$output = tempnam(sys_get_temp_dir(), 'unknot-test-');
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
    $linkedOutput = $output . '-link';
    if (@symlink($output, $linkedOutput)) {
        try {
            try {
                (new JsonWriter())->write($report, $linkedOutput, $fixture);
                throw new RuntimeException('A linked output must be rejected.');
            } catch (RuntimeException $error) {
                check($error->getMessage() === 'The output must not be a link or directory.', 'Unexpected output-link failure.');
            }
            check(json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR) === $report, 'Rejecting a linked output must preserve its target.');
        } finally {
            unlink($linkedOutput);
        }
    } else {
        echo "Output symlink check skipped: symbolic links are unavailable.\n";
    }
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
