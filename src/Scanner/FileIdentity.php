<?php

declare(strict_types=1);

namespace LegacyLens\Scanner;

/** A best-effort change marker, not an atomic filesystem snapshot. */
final class FileIdentity
{
    public static function fromStat(array $stat): array
    {
        return [$stat['dev'], $stat['ino'], $stat['mode'], $stat['size'], $stat['mtime'], $stat['ctime']];
    }
}
