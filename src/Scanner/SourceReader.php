<?php

declare(strict_types=1);

namespace LegacyLens\Scanner;

final class SourceReader
{
    private const FILE_LIMIT = 2097152;
    private const TOTAL_LIMIT = 134217728;

    public function __construct(private FileDiscovery $discovery)
    {
    }

    /** Returns source bytes or a reason why the file was skipped. */
    public function read(string $path, string $root, int &$totalBytes, array|false $identity): array
    {
        clearstatcache(true, $path);
        $resolved = realpath($path);
        if ($resolved === false || !$this->discovery->isWithin($resolved, $root) || is_link($path)
            || !$this->discovery->samePath($path, $resolved)) {
            return ['source' => null, 'reason' => 'file-unavailable'];
        }
        if ($identity === false) {
            return ['source' => null, 'reason' => 'file-changed'];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['source' => null, 'reason' => 'file-unreadable'];
        }
        try {
            $before = fstat($handle);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
                return ['source' => null, 'reason' => 'not-regular-file'];
            }
            if (FileIdentity::fromStat($before) !== $identity) {
                return ['source' => null, 'reason' => 'file-changed'];
            }
            $source = stream_get_contents($handle, self::FILE_LIMIT + 1);
            $after = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $current = @stat($path);
        // Compare both the open handle and the path: replacement can leave the handle pointing at an old file.
        if ($after === false || $current === false || FileIdentity::fromStat($after) !== $identity
            || FileIdentity::fromStat($current) !== $identity || realpath($path) !== $resolved || is_link($path)) {
            return ['source' => null, 'reason' => 'file-changed'];
        }
        if ($source === false || strlen($source) > self::FILE_LIMIT || $totalBytes + strlen($source) > self::TOTAL_LIMIT) {
            return ['source' => null, 'reason' => 'source-limit'];
        }
        $totalBytes += strlen($source);
        return ['source' => $source, 'reason' => null];
    }
}
