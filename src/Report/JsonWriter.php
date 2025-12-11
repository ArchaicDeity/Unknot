<?php

declare(strict_types=1);

namespace Unknot\Report;

use Unknot\Scanner\FileDiscovery;
use RuntimeException;

final class JsonWriter
{
    public function write(array $report, string $destination, string $scanRoot): void
    {
        if (str_contains($destination, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $destination)) {
            throw new RuntimeException('The output must be a local file.');
        }
        if (str_starts_with(str_replace('\\', '/', $destination), '//')
            || (PHP_OS_FAMILY === 'Windows' && (preg_match('/^[a-z]:(?![\\\\\/])/i', $destination) || str_contains(basename($destination), ':')))) {
            throw new RuntimeException('The output must be an absolute or working-directory-relative local file.');
        }
        $parent = realpath(dirname($destination));
        $root = realpath($scanRoot);
        if ($parent === false || $root === false || (new FileDiscovery())->isWithin($parent, $root)) {
            throw new RuntimeException('The output directory must exist outside the scan root.');
        }
        $name = basename($destination);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('The output must be a local file.');
        }
        $target = $parent . DIRECTORY_SEPARATOR . $name;
        if (!$this->isSafeTarget($target)) {
            throw new RuntimeException('The output must not be a link or directory.');
        }
        $incomplete = $this->isIncomplete($report);
        if (file_exists($target) && $incomplete) {
            throw new RuntimeException('The previous report was preserved because the scan was incomplete; select a new output path to save the partial report.');
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($json) > 16 * 1024 * 1024) {
            throw new RuntimeException('The report exceeds the 16 MiB viewer limit.');
        }
        $this->writeAtomically($parent, $target, $json, $incomplete);
    }

    private function writeAtomically(string $parent, string $target, string $json, bool $incomplete): void
    {
        $temporary = $parent . DIRECTORY_SEPARATOR . '.unknot-' . bin2hex(random_bytes(16));
        // ⟡ The file is created exclusively and kept open while writing, so a later path swap cannot redirect the write.
        $oldMask = umask(0077);
        try {
            $handle = @fopen($temporary, 'x+b');
        } finally {
            umask($oldMask);
        }
        if ($handle === false) {
            throw new RuntimeException('Could not create a temporary report.');
        }
        $stat = fstat($handle);
        $identity = $stat === false ? null : [$stat['dev'], $stat['ino']];
        try {
            if ($stat === false || $stat['ino'] === 0 || ($stat['mode'] & 0170000) !== 0100000
                || (PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 0077) !== 0)) {
                throw new RuntimeException('Could not verify the temporary report permissions or identity.');
            }
            $written = 0;
            while ($written < strlen($json)) {
                $count = @fwrite($handle, substr($json, $written, 65536));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('Could not write the report.');
                }
                $written += $count;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush the report.');
            }
            if (!fclose($handle)) {
                $handle = null;
                throw new RuntimeException('Could not close the report.');
            }
            $handle = null;
            if (!$this->sameTemporaryFile($temporary, $identity) || !$this->isSafeTarget($target)) {
                throw new RuntimeException('Could not replace the output report.');
            }
            if ($incomplete && file_exists($target)) {
                throw new RuntimeException('The previous report was preserved because the scan was incomplete; select a new output path to save the partial report.');
            }
            if (!@rename($temporary, $target)) {
                throw new RuntimeException('Could not replace the output report.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($identity !== null && $this->sameTemporaryFile($temporary, $identity)) {
                @unlink($temporary);
            }
        }
    }

    private function sameTemporaryFile(string $path, array $identity): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return $identity[1] !== 0 && $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && [$stat['dev'], $stat['ino']] === $identity;
    }

    private function isSafeTarget(string $target): bool
    {
        clearstatcache(true, $target);
        if (is_link($target)) {
            return false;
        }
        if (!file_exists($target)) {
            return true;
        }
        $resolved = realpath($target);
        return is_file($target) && $resolved !== false && (new FileDiscovery())->samePath($target, $resolved);
    }

    private function isIncomplete(array $report): bool
    {
        foreach ($report['nodes'] ?? [] as $node) {
            if (($node['parseState'] ?? null) !== 'clean') {
                return true;
            }
        }
        foreach ($report['scan']['warnings'] ?? [] as $warning) {
            if (in_array($warning['code'] ?? null, ['link-or-outside-root-skipped', 'not-regular-file'], true)) {
                return true;
            }
        }
        return false;
    }
}
