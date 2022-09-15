<?php

declare(strict_types=1);

namespace LegacyLens\Scanner;

/** Validates worker evidence before it can enter a report. */
final class MethodEvidenceValidator
{
    public function valid($value, array $declarations, string $source): bool
    {
        if (!is_array($value) || !$this->keys($value, ['methods', 'calls'])
            || !is_array($value['methods'] ?? null) || !array_is_list($value['methods'])
            || !is_array($value['calls'] ?? null) || !array_is_list($value['calls'])
            || count($value['methods']) > 100000 || count($value['calls']) > 100000) return false;
        $owners = [];
        foreach ($declarations as $declaration) {
            if (is_array($declaration) && isset($declaration['name'], $declaration['kind'], $declaration['line'])
                && is_string($declaration['name']) && is_string($declaration['kind']) && is_int($declaration['line'])) {
                $owners[json_encode([$declaration['kind'], $declaration['name'], $declaration['line']])] = true;
            }
        }
        $maxLine = substr_count($source, "\n") + 1;
        $lineIndex = new SourceLineIndex($source);
        $methodOwners = [];
        foreach ($value['methods'] as $method) {
            if (!is_array($method) || !$this->keys($method, ['owner', 'ownerKind', 'ownerLine', 'name', 'line', 'visibility', 'static', 'startOffset', 'endOffset'])
                || !$this->name($method['owner'] ?? null) || !$this->name($method['name'] ?? null)
                || !in_array($method['ownerKind'] ?? null, ['class', 'interface', 'trait'], true)
                || !$this->line($method['ownerLine'] ?? null, $maxLine) || !$this->line($method['line'] ?? null, $maxLine)
                || $method['ownerLine'] > $method['line'] || !isset($owners[json_encode([$method['ownerKind'], $method['owner'], $method['ownerLine']])])
                || !in_array($method['visibility'] ?? null, ['public', 'protected', 'private'], true)
                || !is_bool($method['static'] ?? null) || !$this->span($method, $source)
                || $method['line'] !== $lineIndex->lineAt($method['startOffset'])
                || substr($source, $method['startOffset'], $method['endOffset'] - $method['startOffset']) !== $method['name']) return false;
            $methodOwners[json_encode([$method['owner'], $method['name']])] = true;
        }
        foreach ($value['calls'] as $call) {
            if (!is_array($call) || !$this->keys($call, ['line', 'form', 'caller', 'className', 'methodName', 'target', 'confidence', 'reason', 'startOffset', 'endOffset'])
                || !$this->line($call['line'] ?? null, $maxLine) || ($call['form'] ?? null) !== 'static'
                || !array_key_exists('caller', $call) || !array_key_exists('className', $call) || !array_key_exists('methodName', $call)
                || !array_key_exists('target', $call) || $call['target'] !== null || ($call['confidence'] ?? null) !== 'unresolved'
                || !in_array($call['reason'] ?? null, ['dynamic-class', 'dynamic-method', 'static-target-unresolved'], true)
                || ($call['className'] !== null && !$this->name($call['className']))
                || ($call['methodName'] !== null && !$this->name($call['methodName'])) || !$this->span($call, $source)
                || $call['line'] !== $lineIndex->lineAt($call['startOffset'])) return false;
            if ($call['caller'] !== null && (!is_array($call['caller']) || !$this->keys($call['caller'], ['owner', 'method']) || !$this->name($call['caller']['owner'] ?? null)
                || !$this->name($call['caller']['method'] ?? null)
                || !isset($methodOwners[json_encode([$call['caller']['owner'], $call['caller']['method']])]))) return false;
            $reason = $call['className'] === null ? 'dynamic-class' : ($call['methodName'] === null ? 'dynamic-method' : 'static-target-unresolved');
            if ($call['reason'] !== $reason) return false;
        }
        return true;
    }

    private function name($value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 512
            && preg_match('/[\x00-\x1f\x7f]/', $value) === 0;
    }

    private function keys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private function line($value, int $maxLine): bool
    {
        return is_int($value) && $value > 0 && $value <= $maxLine;
    }

    private function span(array $value, string $source): bool
    {
        return is_int($value['startOffset'] ?? null) && is_int($value['endOffset'] ?? null)
            && $value['startOffset'] >= 0 && $value['endOffset'] > $value['startOffset']
            && $value['endOffset'] <= strlen($source);
    }

}
