<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use PhpParser\Node;

/** Reads only inert string concatenation and source-directory expressions. */
final class IncludeExpression
{
    public function path(Node\Expr $expression): ?string
    {
        $stack = [$expression];
        $parts = [];
        $seen = 0;
        while ($stack !== []) {
            if (++$seen > 4096) {
                return null;
            }
            $node = array_pop($stack);
            if ($node instanceof Node\Expr\BinaryOp\Concat) {
                $stack[] = $node->right;
                $stack[] = $node->left;
            } elseif ($node instanceof Node\Scalar\String_) {
                $parts[] = $node->value;
            } elseif ($node instanceof Node\Scalar\MagicConst\Dir && $parts === []) {
                $parts[] = null;
            } else {
                return null;
            }
        }
        $anchored = array_key_exists(0, $parts) && $parts[0] === null;
        if ($anchored) {
            array_shift($parts);
        }
        $path = implode('', $parts);
        if (!$anchored) {
            return $path;
        }
        if (PHP_OS_FAMILY !== 'Windows' && str_contains($path, '\\')) {
            return null;
        }
        if ($path === '' || ($path[0] !== '/' && $path[0] !== '\\')) {
            return null;
        }
        // A leading separator after __DIR__ makes the remainder source-relative.
        return '.' . $path;
    }
}
