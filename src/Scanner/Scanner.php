<?php

declare(strict_types=1);

namespace LegacyLens\Scanner;

use RuntimeException;

final class Scanner
{
    public function __construct(private FileDiscovery $discovery, private Extractor $extractor)
    {
    }

    public function scan(string $root, array $exclusions = []): array
    {
        $normalizedRoot = str_replace('\\', '/', $root);
        if (str_starts_with($normalizedRoot, '//') || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $normalizedRoot)) {
            throw new RuntimeException('The scan root must be a local directory.');
        }
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('The scan root must be an existing local directory.');
        }

        $discovery = $this->discovery->discover($root, $exclusions);
        $files = $discovery['files'];
        $nodes = [];
        $symbols = [];
        $references = [];
        $methodFiles = [];
        $methodCount = 0;
        $callCount = 0;
        $warnings = $discovery['warnings'];
        $totalBytes = 0;
        $reader = new SourceReader($this->discovery);
        foreach ($files as $relative => $path) {
            $id = 'file:' . $relative;
            $read = $reader->read($path, $root, $totalBytes, $discovery['identities'][$relative]);
            $result = $read['source'] === null
                ? ['state' => 'skipped', 'reason' => $read['reason'], 'declarations' => [], 'references' => []]
                : $this->extractor->extract($read['source']);
            if ($result['state'] === 'clean' && !(new MethodEvidenceValidator())->valid($result['methodEvidence'] ?? null, $result['declarations'], $read['source'])) {
                $result = ['state' => 'failed', 'reason' => 'invalid-method-evidence', 'line' => null, 'declarations' => [], 'references' => []];
            }
            $nodes[] = ['id' => $id, 'path' => $relative, 'kind' => 'file', 'parseState' => $result['state'], 'declarations' => $result['declarations']];
            $evidence = $result['state'] === 'clean' ? $result['methodEvidence'] : ['methods' => [], 'calls' => []];
            $methodCount += count($evidence['methods']);
            $callCount += count($evidence['calls']);
            if ($methodCount > 100000 || $callCount > 100000) {
                throw new RuntimeException('The scan exceeds the method evidence limit.');
            }
            foreach ($evidence['methods'] as &$method) {
                unset($method['startOffset'], $method['endOffset']);
            }
            unset($method);
            foreach ($evidence['calls'] as &$call) {
                unset($call['startOffset'], $call['endOffset']);
            }
            unset($call);
            $methodFiles[] = ['fileId' => $id, 'coverage' => $result['state'] === 'clean' ? 'available' : 'unavailable',
                'methods' => $evidence['methods'], 'calls' => $evidence['calls']];
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
        return ['schemaVersion' => 0, 'scan' => ['rootLabel' => basename($root), 'fileCount' => count($nodes), 'warnings' => $warnings],
            'nodes' => $nodes, 'edges' => $edges, 'extensions' => ['legacyMethodEvidence' => ['version' => 1, 'files' => $methodFiles]]];
    }
}
