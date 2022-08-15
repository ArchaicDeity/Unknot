<?php

declare(strict_types=1);

namespace LegacyLens\Report;

use LegacyLens\Scanner\FileDiscovery;
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
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new RuntimeException('The output must not be a link or directory.');
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $temporary = @tempnam($parent, '.legacylens-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary report.');
        }
        try {
            // tempnam may fall back to the system directory, breaking the intended write boundary.
            if (realpath(dirname($temporary)) !== $parent) {
                throw new RuntimeException('Could not create a temporary report in the output directory.');
            }
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Could not write the report.');
            }
            if (is_link($target) || !@rename($temporary, $target)) {
                throw new RuntimeException('Could not replace the output report.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
