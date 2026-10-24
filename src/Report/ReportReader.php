<?php

declare(strict_types=1);

namespace Unknot\Report;

use RuntimeException;

/** Loads one user-selected report; paths inside the report are never opened. */
final class ReportReader
{
    private const MAX_BYTES = 16777216;

    public function read(string $path): array
    {
        if (str_contains($path, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path)
            || str_starts_with(str_replace('\\', '/', $path), '//') || !is_file($path) || is_link($path)) {
            throw new RuntimeException('Choose a local regular report file.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the report.');
        }
        try {
            $json = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('The report exceeds the size limit or could not be read.');
        }
        try {
            $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('The report is not valid JSON.');
        }
        $this->validate($report);
        return $report;
    }

    public function validate($report): void
    {
        if (!is_array($report) || !in_array($report['schemaVersion'] ?? null, [0, 1], true) || !is_array($report['scan'] ?? null)
            || !$this->text($report['scan']['rootLabel'] ?? null) || !is_array($report['nodes'] ?? null)
            || !array_is_list($report['nodes']) || count($report['nodes']) > 10000
            || !is_array($report['edges'] ?? null) || !array_is_list($report['edges']) || count($report['edges']) > 100000
            || !is_array($report['scan']['warnings'] ?? null) || !array_is_list($report['scan']['warnings'])
            || count($report['scan']['warnings']) > 5000 || ($report['scan']['fileCount'] ?? null) !== count($report['nodes'])) {
            throw new RuntimeException('Unsupported or malformed report.');
        }
        $ids = [];
        $methodCount = 0;
        foreach ($report['nodes'] as $node) {
            if (!is_array($node) || !$this->text($node['path'] ?? null) || ($node['id'] ?? null) !== 'file:' . $node['path']
                || isset($ids[$node['id']]) || ($node['kind'] ?? null) !== 'file'
                || !in_array($node['parseState'] ?? null, ['clean', 'failed', 'skipped'], true)
                || !is_array($node['declarations'] ?? null) || !array_is_list($node['declarations'])
                || count($node['declarations']) > 100000) {
                throw new RuntimeException('Malformed file entry.');
            }
            $owners = [];
            foreach ($node['declarations'] as $declaration) {
                if (!is_array($declaration) || !$this->text($declaration['name'] ?? null)
                    || !in_array($declaration['kind'] ?? null, ['class', 'interface', 'trait', 'enum', 'function'], true)
                    || !$this->line($declaration['line'] ?? null)) {
                    throw new RuntimeException('Malformed declaration.');
                }
                if ($declaration['kind'] !== 'function') {
                    $owners[$declaration['kind'] . "\0" . $declaration['name'] . "\0" . $declaration['line']] = true;
                }
            }
            if ($report['schemaVersion'] === 1) {
                if (!is_array($node['methods'] ?? null) || !array_is_list($node['methods'])
                    || count($node['methods']) > 100000 || ($node['parseState'] !== 'clean' && $node['methods'] !== [])) {
                    throw new RuntimeException('Malformed method declarations.');
                }
                foreach ($node['methods'] as $method) {
                    if (!is_array($method) || !$this->text($method['owner'] ?? null)
                        || !in_array($method['ownerKind'] ?? null, ['class', 'interface', 'trait', 'enum'], true)
                        || !$this->line($method['ownerLine'] ?? null)
                        || !isset($owners[$method['ownerKind'] . "\0" . $method['owner'] . "\0" . $method['ownerLine']])
                        || !$this->text($method['name'] ?? null) || !$this->line($method['line'] ?? null)
                        || $method['ownerLine'] > $method['line']
                        || !in_array($method['visibility'] ?? null, ['public', 'protected', 'private'], true)
                        || !is_bool($method['static'] ?? null) || ++$methodCount > 100000) {
                        throw new RuntimeException('Malformed method declaration.');
                    }
                }
            }
            $ids[$node['id']] = true;
        }
        $edgeIds = [];
        foreach ($report['edges'] as $edge) {
            if (!is_array($edge) || !$this->text($edge['id'] ?? null) || isset($edgeIds[$edge['id']])
                || !is_string($edge['from'] ?? null) || !isset($ids[$edge['from']]) || !array_key_exists('to', $edge)
                || ($edge['to'] !== null && (!is_string($edge['to']) || !isset($ids[$edge['to']])))
                || !in_array($edge['type'] ?? null, ['instantiation', 'static-call', 'include', 'extends', 'implements', 'trait-use'], true)
                || !in_array($edge['confidence'] ?? null, ['resolved', 'heuristic', 'unresolved'], true)
                || ($edge['confidence'] === 'unresolved' && $edge['to'] !== null)
                || ($edge['confidence'] === 'resolved' && $edge['to'] === null)
                || !$this->text($edge['reasonCode'] ?? null) || !$this->line($edge['line'] ?? null)
                || !is_array($edge['target'] ?? null) || !is_array($edge['target']['candidates'] ?? null)
                || !array_is_list($edge['target']['candidates'])) {
                throw new RuntimeException('Malformed reference.');
            }
            foreach ($edge['target']['candidates'] as $candidate) {
                if (!is_string($candidate) || !isset($ids[$candidate])) {
                    throw new RuntimeException('Reference candidate is missing.');
                }
            }
            $edgeIds[$edge['id']] = true;
        }
        foreach ($report['scan']['warnings'] as $warning) {
            if (!is_array($warning) || !$this->text($warning['fileId'] ?? null) || !$this->text($warning['code'] ?? null)
                || (($warning['line'] ?? null) !== null && !$this->line($warning['line']))) {
                throw new RuntimeException('Malformed warning.');
            }
        }
        $findings = $report['findings'] ?? [];
        if (!is_array($findings) || !array_is_list($findings) || count($findings) > 100000) {
            throw new RuntimeException('Malformed findings.');
        }
        $findingIds = [];
        foreach ($findings as $finding) {
            if (!is_array($finding) || !$this->text($finding['id'] ?? null) || isset($findingIds[$finding['id']])
                || !is_string($finding['fileId'] ?? null) || !isset($ids[$finding['fileId']]) || !$this->text($finding['api'] ?? null)
                || !in_array($finding['category'] ?? null, ['query', 'connection'], true)
                || ($finding['confidence'] ?? null) !== 'heuristic' || !$this->line($finding['line'] ?? null)) {
                throw new RuntimeException('Malformed finding.');
            }
            $findingIds[$finding['id']] = true;
        }
    }

    private function text($value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 4096;
    }

    private function line($value): bool
    {
        return is_int($value) && $value > 0;
    }
}
