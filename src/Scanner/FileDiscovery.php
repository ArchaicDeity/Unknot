<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FileDiscovery
{
    /** @return array{files: array<string, string>, identities: array<string, array|false>, warnings: list<array>} */
    public function discover(string $root, array $exclusions = []): array
    {
        $warnings = [];
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            function ($entry) use ($root, $prefix, $exclusions, &$warnings): bool {
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($prefix)));
                foreach ($exclusions as $excluded) {
                    $excluded = trim(str_replace('\\', '/', $excluded), '/');
                    if ($excluded !== '' && ($relative === $excluded || str_starts_with($relative, $excluded . '/'))) {
                        return false;
                    }
                }

                if (in_array(strtolower($entry->getFilename()), ['.git', 'vendor', 'node_modules', 'dist', 'build'], true)) {
                    return false;
                }

                $resolved = $entry->getRealPath();
                if ($entry->isLink() || $resolved === false || !$this->isWithin($resolved, $root)
                    || !$this->samePath($entry->getPathname(), $resolved)) {
                    $warnings[] = ['fileId' => 'file:' . $relative, 'code' => 'link-or-outside-root-skipped', 'line' => null];
                    if (count($warnings) > 5000) {
                        throw new RuntimeException('The scan exceeds the diagnostic limit.');
                    }
                    return false;
                }

                if ($entry->isDir()) {
                    return true;
                }

                return $entry->isFile() && in_array(strtolower($entry->getExtension()), ['php', 'phtml', 'inc'], true);
            }
        );

        $files = [];
        $identities = [];
        foreach (new RecursiveIteratorIterator($filter) as $entry) {
            $path = $entry->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($prefix)));
            clearstatcache(true, $path);
            $resolved = realpath($path);
            if ($resolved === false || !$this->isWithin($resolved, $root) || is_link($path)
                || !$this->samePath($path, $resolved)) {
                $warnings[] = ['fileId' => 'file:' . $relative, 'code' => 'link-or-outside-root-skipped', 'line' => null];
                if (count($warnings) > 5000) {
                    throw new RuntimeException('The scan exceeds the diagnostic limit.');
                }
                continue;
            }
            if (isset($files[$relative])) {
                throw new RuntimeException('Conflicting file paths in the scan.');
            }
            $files[$relative] = $path;
            $stat = @stat($path);
            $identities[$relative] = $stat === false ? false : FileIdentity::fromStat($stat);
            if (count($files) > 10000) {
                throw new RuntimeException('The scan exceeds the 10,000-file limit.');
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'identities' => $identities, 'warnings' => $warnings];
    }

    public function isWithin(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root . '/');
    }

    public function samePath(string $path, string $resolved): bool
    {
        $path = str_replace('\\', '/', $path);
        $resolved = str_replace('\\', '/', $resolved);
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($path, $resolved) === 0 : $path === $resolved;
    }
}
