<?php

declare(strict_types=1);

use Unknot\Scanner\FileDiscovery;
use Unknot\Scanner\FileIdentity;
use Unknot\Scanner\SourceReader;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkSource(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unknot-source-' . bin2hex(random_bytes(8));
$root = $scratch . '/input';
$source = $root . '/Example.php';
$replacement = $scratch . '/replacement.tmp';
$outside = $scratch . '/outside.php';
checkSource(mkdir($scratch) && mkdir($root), 'Could not create the source-change fixture.');
try {
    $discovery = new FileDiscovery();
    $reader = new SourceReader($discovery);
    $bytes = 0;
    file_put_contents($source, '<?php class Original {}');
    $snapshot = $discovery->discover(realpath($root));
    $stable = $reader->read($snapshot['files']['Example.php'], realpath($root), $bytes, $snapshot['identities']['Example.php']);
    checkSource($stable['source'] === '<?php class Original {}', 'An unchanged file must remain readable.');

    file_put_contents($source, '<?php class DifferentLength {}');
    $changed = $reader->read($snapshot['files']['Example.php'], realpath($root), $bytes, $snapshot['identities']['Example.php']);
    checkSource($changed['source'] === null && $changed['reason'] === 'file-changed', 'An edit after discovery must be reported without parsing it.');
    checkSource($bytes === strlen($stable['source']), 'Rejected source must not count toward accepted bytes.');

    // A separate process can change a source between discovery and reading.
    file_put_contents($source, '<?php class Original {}');
    $snapshot = $discovery->discover(realpath($root));
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $writer = proc_open([PHP_BINARY, '-n', '-r', 'exit(file_put_contents($argv[1], "<?php class ConcurrentChange {} ") === false ? 1 : 0);', $source], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    checkSource(is_resource($writer), 'Could not start the concurrent writer.');
    checkSource(proc_close($writer) === 0, 'The concurrent writer failed.');
    $changed = $reader->read($source, realpath($root), $bytes, $snapshot['identities']['Example.php']);
    checkSource($changed['source'] === null && $changed['reason'] === 'file-changed', 'A separate-process edit must be reported: ' . json_encode(['reason' => $changed['reason'], 'sourceLength' => $changed['source'] === null ? null : strlen($changed['source']), 'discoveredSize' => $snapshot['identities']['Example.php'][3]]));

    $sibling = $scratch . '/input-extra.php';
    file_put_contents($sibling, '<?php class OutsideRoot {}');
    $outsideResult = $reader->read($root . '/../input-extra.php', realpath($root), $bytes, false);
    checkSource($outsideResult['source'] === null && $outsideResult['reason'] === 'file-unavailable', 'A traversal to a sibling must be rejected.');
    checkSource(!$discovery->isWithin(realpath($sibling), realpath($root)), 'A sibling with the same path prefix must remain outside the root.');

    $directoryLink = $root . '/outside-directory';
    if (@symlink($scratch, $directoryLink)) {
        $linkedDiscovery = $discovery->discover(realpath($root));
        checkSource(count(array_filter($linkedDiscovery['warnings'], static fn (array $warning): bool => $warning['code'] === 'link-or-outside-root-skipped')) === 1, 'An outside directory link must be reported.');
        checkSource(count($linkedDiscovery['files']) === 1, 'Discovery must not follow an outside directory link.');
        unlink($directoryLink);
    } else {
        echo "Directory symlink check skipped: symbolic links are unavailable.\n";
    }

    file_put_contents($source, '<?php class Original {}');
    $snapshot = $discovery->discover(realpath($root));
    $oldTime = filemtime($source);
    file_put_contents($replacement, '<?php class Replaced {}');
    checkSource(filesize($replacement) === filesize($source), 'Replacement fixture must have the same size.');
    checkSource(unlink($source) && rename($replacement, $source) && touch($source, $oldTime), 'Could not replace the source fixture.');
    clearstatcache(true, $source);
    $current = FileIdentity::fromStat(stat($source));
    if ($current !== $snapshot['identities']['Example.php']) {
        $changed = $reader->read($source, realpath($root), $bytes, $snapshot['identities']['Example.php']);
        checkSource($changed['source'] === null && $changed['reason'] === 'file-changed', 'A detectable same-size replacement must be rejected.');
    } else {
        echo "Same-size replacement check skipped: filesystem metadata did not distinguish the files.\n";
    }

    $snapshot = $discovery->discover(realpath($root));
    file_put_contents($outside, '<?php class Outside {}');
    checkSource(unlink($source), 'Could not remove the source fixture.');
    if (@symlink($outside, $source)) {
        $linked = $reader->read($source, realpath($root), $bytes, $snapshot['identities']['Example.php']);
        checkSource($linked['source'] === null && $linked['reason'] === 'file-unavailable', 'A source replaced by an outside link must be rejected.');
    } else {
        echo "Source symlink replacement check skipped: symbolic links are unavailable.\n";
    }
} finally {
    if (is_link($root . '/outside-directory')) {
        unlink($root . '/outside-directory');
    }
    if (is_link($source) || is_file($source)) {
        unlink($source);
    }
    foreach ([$replacement, $outside, $scratch . '/input-extra.php'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($root);
    rmdir($scratch);
}

echo "Source-change checks passed.\n";
