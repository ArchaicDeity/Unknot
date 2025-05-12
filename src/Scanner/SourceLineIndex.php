<?php

declare(strict_types=1);

namespace Unknot\Scanner;

/** Bounds line lookup work even when a worker returns many source offsets. */
final class SourceLineIndex
{
    private array $blocks = [1];

    public function __construct(private string $source)
    {
        for ($offset = 4096; $offset <= strlen($source); $offset += 4096) {
            $this->blocks[] = end($this->blocks) + substr_count($source, "\n", $offset - 4096, 4096);
        }
    }

    public function lineAt(int $offset): int
    {
        $block = intdiv($offset, 4096);
        $start = $block * 4096;
        return $this->blocks[$block] + substr_count($this->source, "\n", $start, $offset - $start);
    }
}
