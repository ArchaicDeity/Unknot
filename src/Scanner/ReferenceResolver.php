<?php

declare(strict_types=1);

namespace LegacyLens\Scanner;

final class ReferenceResolver
{
    /** @param array<string, list<array{fileId: string, kind: string, conditional: bool}>> $symbols */
    public function resolve(string $file, array $reference, array $files, array $symbols): array
    {
        $name = $reference['name'];
        $target = null;
        $confidence = 'unresolved';
        $reason = 'dynamic-expression';
        $candidates = [];
        $kindMismatch = null;

        if (isset($reference['contextReason'])) {
            $reason = $reference['contextReason'];
        } elseif ($name !== null && $reference['type'] === 'include') {
            $candidate = $this->relativeInclude($file, $name);
            if ($candidate !== null && isset($files[$candidate])) {
                $target = 'file:' . $candidate;
                $candidates = [$target];
                $confidence = 'heuristic';
                $reason = 'source-relative-include';
            } else {
                $reason = 'include-target-not-resolved';
            }
        } elseif ($name !== null) {
            $declarations = $symbols[strtolower($name)] ?? [];
            $candidates = array_values(array_unique(array_column($declarations, 'fileId')));
            sort($candidates, SORT_STRING);
            if (count($declarations) === 1 && !$declarations[0]['conditional']) {
                if (isset($reference['expectedKind']) && $reference['expectedKind'] !== $declarations[0]['kind']) {
                    $reason = 'symbol-kind-mismatch';
                    $kindMismatch = ['expected' => $reference['expectedKind'], 'found' => $declarations[0]['kind']];
                } else {
                    $target = $declarations[0]['fileId'];
                    $confidence = 'resolved';
                    $reason = 'named-symbol';
                }
            } else {
                // Multiple or conditional declarations cannot establish a single runtime target.
                $reason = count($declarations) > 1 ? 'ambiguous-symbol'
                    : (count($declarations) === 1 ? 'conditional-symbol' : 'symbol-not-found');
            }
        }

        $edge = [
            'from' => 'file:' . $file,
            'to' => $target,
            'type' => $reference['type'],
            'confidence' => $confidence,
            'reasonCode' => $reason,
            'target' => [
                'kind' => $name === null ? 'dynamic' : ($reference['type'] === 'include' ? 'literal-include' : 'symbol'),
                'name' => $reference['type'] === 'include' ? null : $name,
                'candidates' => $candidates,
            ],
            'line' => $reference['line'],
            'startOffset' => $reference['startOffset'],
            'endOffset' => $reference['endOffset'],
        ];
        if (isset($reference['keyword'])) {
            $edge['target']['syntax'] = $reference['keyword'];
        }
        if ($kindMismatch !== null) {
            $edge['target']['expectedKind'] = $kindMismatch['expected'];
            $edge['target']['foundKind'] = $kindMismatch['found'];
        }
        $edge['id'] = 'edge:' . hash('sha256', json_encode($edge, JSON_THROW_ON_ERROR));
        return $edge;
    }

    private function relativeInclude(string $file, string $include): ?string
    {
        if ($include === '' || str_contains($include, ':') || str_starts_with($include, '/') || str_starts_with($include, '\\')) {
            return null;
        }
        $parts = explode('/', str_replace('\\', '/', dirname($file) . '/' . $include));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($normalized === []) {
                    return null;
                }
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }
        return implode('/', $normalized);
    }
}
