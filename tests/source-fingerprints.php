<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Unknot\Report\ReportReader;
use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\IsolatedExtractor;
use Unknot\Scanner\Scanner;

function checkFingerprint(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-fingerprint-' . bin2hex(random_bytes(8));
checkFingerprint(mkdir($root), 'Could not create the temporary fixture.');
$good = $root . '/Good.php';
$bad = $root . '/Bad.php';
$large = $root . '/Large.php';
try {
    $source = "<?php\nclass Example { public function first() {} }\n";
    file_put_contents($good, $source);
    file_put_contents($bad, '<?php class {');
    file_put_contents($large, str_repeat('x', 2097153));
    $scanner = new Scanner(new FileDiscovery(), new IsolatedExtractor());
    $normal = $scanner->scan($root);
    checkFingerprint($normal['schemaVersion'] === 1, 'Ordinary scans must keep schema 1.');
    foreach ($normal['nodes'] as $node) checkFingerprint(!array_key_exists('sourceSha256', $node), 'Fingerprints must be opt-in.');
    $report = $scanner->scan($root, [], true);
    checkFingerprint($report['schemaVersion'] === 2, 'Fingerprint reports must use schema 2.');
    $nodes = array_column($report['nodes'], null, 'path');
    checkFingerprint($nodes['Good.php']['sourceSha256'] === hash('sha256', $source), 'The digest must match the parsed bytes.');
    checkFingerprint($nodes['Bad.php']['parseState'] === 'failed' && $nodes['Bad.php']['sourceSha256'] === null, 'Parse failures must not claim a usable fingerprint.');
    checkFingerprint($nodes['Large.php']['parseState'] === 'skipped' && $nodes['Large.php']['sourceSha256'] === null, 'Oversized sources must not claim a usable fingerprint.');
    $reader = new ReportReader();
    $reader->validate($report);
    $goodIndex = array_search('Good.php', array_column($report['nodes'], 'path'), true);
    foreach ([null, str_repeat('A', 64), str_repeat('a', 64) . "\n", str_repeat('a', 63)] as $invalid) {
        $copy = $report;
        $copy['nodes'][$goodIndex]['sourceSha256'] = $invalid;
        try { $reader->validate($copy); throw new LogicException('Invalid fingerprint was accepted.'); }
        catch (RuntimeException $error) { /* Expected validation failure. */ }
    }
    $copy = $report;
    unset($copy['nodes'][$goodIndex]['sourceSha256']);
    try { $reader->validate($copy); throw new LogicException('Missing fingerprint was accepted.'); }
    catch (RuntimeException $error) { /* Expected validation failure. */ }
    file_put_contents($good, $source . "// changed\n");
    $changed = $scanner->scan($root, [], true);
    $changedNodes = array_column($changed['nodes'], null, 'path');
    checkFingerprint($changedNodes['Good.php']['sourceSha256'] !== $nodes['Good.php']['sourceSha256'], 'A source edit must change its fingerprint.');
    echo "Source fingerprint checks passed.\n";
} finally {
    foreach ([$good, $bad, $large] as $file) if (is_file($file)) unlink($file);
    rmdir($root);
}
