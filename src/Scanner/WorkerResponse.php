<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use JsonException;
use stdClass;

/** Checks and copies only supported worker fields before reporting them. */
final class WorkerResponse
{
    public function decode(string $json, int $expectedLength, string $source): ?array
    {
        if (strlen($json) !== $expectedLength) return null;
        try {
            $result = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return null;
        }
        if (!($result instanceof stdClass)) return null;
        $result = (array) $result;
        if (!in_array($result['state'] ?? null, ['clean', 'failed'], true)
            || !is_array($result['declarations'] ?? null) || !array_is_list($result['declarations'])
            || !is_array($result['references'] ?? null) || !array_is_list($result['references'])) return null;
        $maxLine = substr_count($source, "\n") + 1;
        if ($result['state'] === 'failed') {
            if ($result['declarations'] !== [] || $result['references'] !== [] || array_key_exists('methodEvidence', $result)
                || !$this->line($result['line'] ?? null, $maxLine)
                || (isset($result['reason']) && $result['reason'] !== 'unsupported-hack-syntax')) return null;
            $failed = ['state' => 'failed', 'line' => $result['line'], 'declarations' => [], 'references' => []];
            if (isset($result['reason'])) $failed['reason'] = $result['reason'];
            return $failed;
        }
        if (isset($result['reason']) || count($result['declarations']) > 100000 || count($result['references']) > 100000) return null;
        $declarations = [];
        foreach ($result['declarations'] as $declaration) {
            if (!($declaration instanceof stdClass)) return null;
            $declaration = (array) $declaration;
            if (!in_array($declaration['kind'] ?? null, ['class', 'interface', 'trait', 'enum', 'function'], true)
                || !$this->text($declaration['name'] ?? null) || !$this->line($declaration['line'] ?? null, $maxLine)) return null;
            $item = ['kind' => $declaration['kind'], 'name' => $declaration['name'], 'line' => $declaration['line']];
            if ($item['kind'] !== 'function') {
                if (!is_bool($declaration['conditional'] ?? null)) return null;
                $item['conditional'] = $declaration['conditional'];
            }
            $declarations[] = $item;
        }
        $index = new SourceLineIndex($source);
        $references = [];
        $lastOffset = 0;
        foreach ($result['references'] as $reference) {
            if (!($reference instanceof stdClass)) return null;
            $reference = (array) $reference;
            if (!in_array($reference['type'] ?? null, ['instantiation', 'static-call', 'include', 'extends', 'implements', 'trait-use'], true)
                || !array_key_exists('name', $reference) || ($reference['name'] !== null && !is_string($reference['name']))
                || (is_string($reference['name']) && strlen($reference['name']) > 4096)
                || !$this->line($reference['line'] ?? null, $maxLine) || !is_int($reference['startOffset'] ?? null)
                || !is_int($reference['endOffset'] ?? null) || $reference['startOffset'] < $lastOffset
                || $reference['endOffset'] <= $reference['startOffset'] || $reference['endOffset'] > strlen($source)
                || $reference['line'] !== $index->lineAt($reference['startOffset'])) return null;
            $lastOffset = $reference['startOffset'];
            $type = $reference['type'];
            if (($type !== 'include' && $reference['name'] === '')
                || (in_array($type, ['extends', 'implements', 'trait-use'], true) && !is_string($reference['name']))) return null;
            $expected = ['instantiation' => ['class'], 'extends' => ['class', 'interface'], 'implements' => ['interface'], 'trait-use' => ['trait']];
            if (isset($expected[$type])) {
                if (!in_array($reference['expectedKind'] ?? null, $expected[$type], true)) return null;
            } elseif (array_key_exists('expectedKind', $reference)) return null;
            if ((isset($reference['keyword']) || isset($reference['contextReason'])) && !in_array($type, ['instantiation', 'static-call'], true)) return null;
            if (isset($reference['contextReason']) && !isset($reference['keyword'])) return null;
            $item = ['type' => $type, 'name' => $reference['name'], 'line' => $reference['line'],
                'startOffset' => $reference['startOffset'], 'endOffset' => $reference['endOffset']];
            foreach (['expectedKind', 'keyword', 'contextReason'] as $field) {
                if (array_key_exists($field, $reference)) {
                    $allowed = $field === 'expectedKind' ? ['class', 'interface', 'trait']
                        : ($field === 'keyword' ? ['self', 'parent', 'static']
                            : ['late-static-binding', 'trait-context-dependent', 'class-context-unavailable', 'parent-class-unavailable']);
                    if (!in_array($reference[$field], $allowed, true)) return null;
                    $item[$field] = $reference[$field];
                }
            }
            $references[] = $item;
        }
        $evidence = $result['methodEvidence'] ?? null;
        if (!($evidence instanceof stdClass)) return null;
        $evidence = (array) $evidence;
        if (!is_array($evidence['methods'] ?? null) || !is_array($evidence['calls'] ?? null)) return null;
        foreach ($evidence['methods'] as $key => $method) {
            if (!($method instanceof stdClass)) return null;
            $evidence['methods'][$key] = (array) $method;
        }
        foreach ($evidence['calls'] as $key => $call) {
            if (!($call instanceof stdClass)) return null;
            $call = (array) $call;
            if (isset($call['caller'])) {
                if (!($call['caller'] instanceof stdClass)) return null;
                $call['caller'] = (array) $call['caller'];
            }
            $evidence['calls'][$key] = $call;
        }
        if (!(new MethodEvidenceValidator())->valid($evidence, $declarations, $source)) return null;
        return ['state' => 'clean', 'declarations' => $declarations, 'references' => $references, 'methodEvidence' => $evidence];
    }

    private function text($value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 4096;
    }

    private function line($value, int $maximum): bool
    {
        return is_int($value) && $value >= 1 && $value <= $maximum;
    }
}
