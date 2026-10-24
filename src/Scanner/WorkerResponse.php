<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use JsonException;
use stdClass;

/** Validates the parser worker's response before it reaches the scanner. */
final class WorkerResponse
{
    public function decode(string $json, int $expectedLength, string $source): ?array
    {
        if (strlen($json) !== $expectedLength) {
            return null;
        }
        try {
            $result = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return null;
        }
        if (!$result instanceof stdClass || !in_array($result->state ?? null, ['clean', 'failed'], true)
            || !is_array($result->declarations ?? null) || !is_array($result->references ?? null)) {
            return null;
        }
        $lines = substr_count($source, "\n") + 1;
        if ($result->state === 'failed') {
            if ($result->declarations !== [] || $result->references !== []
                || (property_exists($result, 'methods') && $result->methods !== [])
                || !$this->validLine($result->line ?? null, $lines)
                || (isset($result->reason) && $result->reason !== 'unsupported-hack-syntax')) {
                return null;
            }
            $failed = ['state' => 'failed', 'line' => $result->line, 'declarations' => [], 'references' => []];
            if (isset($result->reason)) {
                $failed['reason'] = $result->reason;
            }
            return $failed;
        }
        if (isset($result->reason) || !is_array($result->methods ?? null) || count($result->methods) > 100000
            || count($result->declarations) > 100000 || count($result->references) > 100000) {
            return null;
        }
        $declarations = [];
        $owners = [];
        foreach ($result->declarations as $declaration) {
            if (!$declaration instanceof stdClass || !in_array($declaration->kind ?? null, ['class', 'interface', 'trait', 'enum', 'function'], true)
                || !is_string($declaration->name ?? null) || $declaration->name === '' || !$this->validLine($declaration->line ?? null, $lines)) {
                return null;
            }
            $item = ['kind' => $declaration->kind, 'name' => $declaration->name, 'line' => $declaration->line];
            if ($declaration->kind !== 'function') {
                if (!is_bool($declaration->conditional ?? null)) {
                    return null;
                }
                $item['conditional'] = $declaration->conditional;
                $owners[$item['kind'] . "\0" . $item['name'] . "\0" . $item['line']] = true;
            }
            $declarations[] = $item;
        }
        $methods = [];
        $methodOffset = 0;
        $methodLine = 1;
        foreach ($result->methods as $method) {
            if (!$method instanceof stdClass || !is_string($method->owner ?? null) || strlen($method->owner) > 4096
                || !in_array($method->ownerKind ?? null, ['class', 'interface', 'trait', 'enum'], true)
                || !$this->validLine($method->ownerLine ?? null, $lines)
                || !isset($owners[$method->ownerKind . "\0" . $method->owner . "\0" . $method->ownerLine])
                || !is_string($method->name ?? null) || strlen($method->name) > 4096
                || preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/D', $method->name) !== 1
                || !$this->validLine($method->line ?? null, $lines) || $method->ownerLine > $method->line
                || !in_array($method->visibility ?? null, ['public', 'protected', 'private'], true)
                || !is_bool($method->static ?? null) || !is_int($method->startOffset ?? null) || !is_int($method->endOffset ?? null)
                || $method->startOffset < $methodOffset || ($methods !== [] && $method->startOffset === $methodOffset)
                || $method->endOffset <= $method->startOffset
                || $method->endOffset > strlen($source)
                || substr($source, $method->startOffset, $method->endOffset - $method->startOffset) !== $method->name) {
                return null;
            }
            $methodLine += substr_count($source, "\n", $methodOffset, $method->startOffset - $methodOffset);
            $methodOffset = $method->startOffset;
            if ($methodLine !== $method->line) return null;
            $methods[] = ['owner' => $method->owner, 'ownerKind' => $method->ownerKind, 'ownerLine' => $method->ownerLine,
                'name' => $method->name, 'line' => $method->line, 'visibility' => $method->visibility, 'static' => $method->static,
                'startOffset' => $method->startOffset, 'endOffset' => $method->endOffset];
        }
        $references = [];
        $lastOffset = 0;
        $lineAtOffset = 1;
        foreach ($result->references as $reference) {
            if (!$reference instanceof stdClass || !in_array($reference->type ?? null, ['instantiation', 'static-call', 'include', 'extends', 'implements', 'trait-use'], true)
                || !property_exists($reference, 'name') || (!is_string($reference->name) && $reference->name !== null)
                || !$this->validLine($reference->line ?? null, $lines)
                || !is_int($reference->startOffset ?? null) || !is_int($reference->endOffset ?? null)
                || $reference->startOffset < $lastOffset || $reference->endOffset <= $reference->startOffset || $reference->endOffset > strlen($source)) {
                return null;
            }
            if ($reference->startOffset > $lastOffset) {
                $lineAtOffset += substr_count($source, "\n", $lastOffset, $reference->startOffset - $lastOffset);
                $lastOffset = $reference->startOffset;
            }
            if ($reference->line !== $lineAtOffset
                || ($reference->type !== 'include' && $reference->name === '')
                || (in_array($reference->type, ['extends', 'implements', 'trait-use'], true)
                    && !is_string($reference->name))
                || ((property_exists($reference, 'keyword') || property_exists($reference, 'contextReason'))
                    && !in_array($reference->type, ['instantiation', 'static-call'], true))
                || (property_exists($reference, 'contextReason') && !property_exists($reference, 'keyword'))) {
                return null;
            }
            $expectedKinds = ['instantiation' => 'class', 'extends' => null, 'implements' => 'interface', 'trait-use' => 'trait'];
            if (array_key_exists($reference->type, $expectedKinds)) {
                $kind = $reference->expectedKind ?? null;
                if (($reference->type === 'extends' && !in_array($kind, ['class', 'interface'], true))
                    || ($reference->type !== 'extends' && $kind !== $expectedKinds[$reference->type])) {
                    return null;
                }
            } elseif (isset($reference->expectedKind)) {
                return null;
            }
            $item = ['type' => $reference->type, 'name' => $reference->name, 'line' => $reference->line,
                'startOffset' => $reference->startOffset, 'endOffset' => $reference->endOffset];
            foreach (['expectedKind', 'keyword', 'contextReason'] as $field) {
                if (property_exists($reference, $field)) {
                    $values = $field === 'expectedKind' ? ['class', 'interface', 'trait']
                        : ($field === 'keyword' ? ['self', 'parent', 'static']
                            : ['late-static-binding', 'trait-context-dependent', 'class-context-unavailable', 'parent-class-unavailable']);
                    if (!in_array($reference->$field, $values, true)) {
                        return null;
                    }
                    $item[$field] = $reference->$field;
                }
            }
            $references[] = $item;
        }
        $findings = [];
        $lastFindingOffset = 0;
        $findingLine = 1;
        if (!is_array($result->findings ?? []) || count($result->findings ?? []) > 100000) {
            return null;
        }
        foreach ($result->findings ?? [] as $finding) {
            if (!$finding instanceof stdClass || !in_array($finding->api ?? null, [
                'pdo::__construct', 'mysqli::__construct', 'mysqli_connect', 'mysqli_real_connect',
                'mysqli_query', 'mysqli_prepare', 'mysqli_execute_query', 'pdo::query', 'pdo::prepare',
                'pdo::exec', 'mysqli::query', 'mysqli::prepare', 'mysqli::real_query',
            ], true) || !in_array($finding->category ?? null, ['connection', 'query'], true)
                || ($finding->confidence ?? null) !== 'heuristic' || !$this->validLine($finding->line ?? null, $lines)
                || !is_int($finding->startOffset ?? null) || !is_int($finding->endOffset ?? null)
                || $finding->startOffset < $lastFindingOffset || $finding->endOffset <= $finding->startOffset
                || $finding->endOffset > strlen($source)) {
                return null;
            }
            $isConnection = in_array($finding->api, ['pdo::__construct', 'mysqli::__construct', 'mysqli_connect', 'mysqli_real_connect'], true);
            if ($finding->category !== ($isConnection ? 'connection' : 'query')) {
                return null;
            }
            $findingLine += substr_count($source, "\n", $lastFindingOffset, $finding->startOffset - $lastFindingOffset);
            $lastFindingOffset = $finding->startOffset;
            if ($findingLine !== $finding->line) {
                return null;
            }
            $findings[] = ['api' => $finding->api, 'category' => $finding->category, 'confidence' => 'heuristic',
                'line' => $finding->line, 'startOffset' => $finding->startOffset, 'endOffset' => $finding->endOffset];
        }
        return ['state' => 'clean', 'declarations' => $declarations, 'methods' => $methods, 'references' => $references, 'findings' => $findings];
    }

    private function validLine($line, int $maximum): bool
    {
        return is_int($line) && $line >= 1 && $line <= $maximum;
    }
}
