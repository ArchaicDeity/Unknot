<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use RuntimeException;

final class Scanner
{
    public function __construct(
        private FileDiscovery $discovery,
        private IsolatedExtractor $extractor,
        private float $timeLimitSeconds = 120.0
    )
    {
        if ($timeLimitSeconds <= 0) {
            throw new RuntimeException('Scan timeout must be positive.');
        }
    }

    public function scan(string $root, array $exclusions = [], bool $sourceFingerprints = false): array
    {
        $normalizedRoot = str_replace('\\', '/', $root);
        if (str_starts_with($normalizedRoot, '//') || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $normalizedRoot)) {
            throw new RuntimeException('The scan root must be a local directory.');
        }
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('The scan root must be an existing local directory.');
        }

        $started = hrtime(true);
        $discovery = $this->discovery->discover($root, $exclusions);
        $files = $discovery['files'];
        $nodes = [];
        $symbols = [];
        $references = [];
        $findings = [];
        $methodCount = 0;
        $warnings = $discovery['warnings'];
        $totalBytes = 0;
        foreach ($files as $relative => $path) {
            $id = 'file:' . $relative;
            if ((hrtime(true) - $started) / 1000000000 >= $this->timeLimitSeconds) {
                $result = ['state' => 'skipped', 'reason' => 'scan-time-limit', 'declarations' => [], 'references' => []];
            } else {
                // Reading shares the parser's deadline so a blocked open cannot hang the scanning process.
                $result = $this->extractor->extractFile($path, $root, $discovery['identities'][$relative], 134217728 - $totalBytes);
                $totalBytes += $result['sourceBytes'] ?? 0;
            }
            $fileMethods = [];
            foreach ($result['methods'] ?? [] as $method) {
                unset($method['startOffset'], $method['endOffset']);
                $fileMethods[] = $method;
                if (++$methodCount > 100000) {
                    throw new RuntimeException('The scan exceeds the method declaration limit.');
                }
            }
            $node = ['id' => $id, 'path' => $relative, 'kind' => 'file', 'parseState' => $result['state'],
                'declarations' => $result['declarations'], 'methods' => $fileMethods];
            if ($sourceFingerprints) {
                if ($result['state'] === 'clean' && !isset($result['sourceSha256'])) {
                    throw new RuntimeException('A clean source file has no validated fingerprint.');
                }
                $node['sourceSha256'] = $result['state'] === 'clean' ? $result['sourceSha256'] : null;
            }
            $nodes[] = $node;
            if ($result['state'] !== 'clean') {
                $warnings[] = ['fileId' => $id, 'code' => $result['reason'] ?? 'parse-error', 'line' => $result['line'] ?? null];
                if (count($warnings) > 5000) {
                    throw new RuntimeException('The scan exceeds the diagnostic limit.');
                }
            }
            foreach ($result['declarations'] as $declaration) {
                if ($declaration['kind'] !== 'function') {
                    $symbols[strtolower($declaration['name'])][] = ['fileId' => $id, 'kind' => $declaration['kind'], 'conditional' => $declaration['conditional']];
                }
            }
            foreach ($result['references'] as $reference) {
                $references[] = ['file' => $relative, 'reference' => $reference];
                if (count($references) > 100000) {
                    throw new RuntimeException('The scan exceeds the reference limit.');
                }
            }
            foreach ($result['findings'] ?? [] as $finding) {
                $finding['fileId'] = $id;
                $finding['id'] = 'finding:' . hash('sha256', json_encode($finding, JSON_THROW_ON_ERROR));
                $findings[] = $finding;
                if (count($findings) > 100000) {
                    throw new RuntimeException('The scan exceeds the finding limit.');
                }
            }
        }

        $edges = [];
        $resolver = new ReferenceResolver();
        foreach ($references as $item) {
            $reference = $item['reference'];
            $edge = $resolver->resolve($item['file'], $reference, $files, $symbols);
            $edges[] = $edge;
            if ($edge['confidence'] === 'unresolved') {
                $warnings[] = ['fileId' => $edge['from'], 'code' => $edge['reasonCode'], 'line' => $reference['line']];
            }
            if (count($warnings) > 5000) {
                throw new RuntimeException('The scan exceeds the diagnostic limit.');
            }
        }

        usort($warnings, static fn (array $a, array $b): int => [$a['fileId'], $a['line'] ?? 0, $a['code']] <=> [$b['fileId'], $b['line'] ?? 0, $b['code']]);
        return ['schemaVersion' => $sourceFingerprints ? 2 : 1, 'scan' => ['rootLabel' => basename($root), 'fileCount' => count($nodes), 'warnings' => $warnings], 'nodes' => $nodes, 'edges' => $edges, 'findings' => $findings];
    }
}
