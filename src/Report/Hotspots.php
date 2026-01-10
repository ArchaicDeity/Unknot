<?php

declare(strict_types=1);

namespace Unknot\Report;

/** Counts distinct resolved neighbours; inferred and unresolved edges do not increase the score. */
final class Hotspots
{
    public function calculate(array $report): array
    {
        $rows = [];
        foreach ($report['nodes'] as $node) {
            $rows[$node['id']] = ['id' => $node['id'], 'path' => $node['path'], 'incoming' => [], 'outgoing' => [], 'database' => 0];
        }
        foreach ($report['edges'] as $edge) {
            if ($edge['confidence'] === 'resolved' && $edge['to'] !== null && $edge['to'] !== $edge['from']) {
                $rows[$edge['from']]['outgoing'][$edge['to']] = true;
                $rows[$edge['to']]['incoming'][$edge['from']] = true;
            }
        }
        foreach ($report['findings'] ?? [] as $finding) {
            if ($finding['category'] === 'query') {
                $rows[$finding['fileId']]['database']++;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $inbound = count($row['incoming']);
            $outbound = count($row['outgoing']);
            $result[] = ['id' => $row['id'], 'path' => $row['path'], 'inbound' => $inbound, 'outbound' => $outbound,
                'database' => $row['database'], 'score' => 2 * $inbound + $outbound + 3 * $row['database']];
        }
        usort($result, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']));
        return $result;
    }
}
