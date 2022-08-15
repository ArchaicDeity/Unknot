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
    public function read(string $path, string $root, int &$totalBytes): array
    {
        clearstatcache(true, $path);
        $resolved = realpath($path);
        if ($resolved === false || !$this->discovery->isWithin($resolved, $root) || is_link($path)) {
            return ['source' => null, 'reason' => 'file-unavailable'];
        }
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            return ['source' => null, 'reason' => 'file-unreadable'];
        }
        try {
            $before = fstat($handle);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
                return ['source' => null, 'reason' => 'not-regular-file'];
            }
            $source = stream_get_contents($handle, self::FILE_LIMIT + 1);
            $after = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        if ($after === false || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || realpath($path) !== $resolved || is_link($path)) {
            return ['source' => null, 'reason' => 'file-changed'];
        }
        if ($source === false || strlen($source) > self::FILE_LIMIT || $totalBytes + strlen($source) > self::TOTAL_LIMIT) {
            return ['source' => null, 'reason' => 'source-limit'];
        }
        $totalBytes += strlen($source);
        return ['source' => $source, 'reason' => null];
    }
}
