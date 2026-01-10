<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Records a deliberately small set of syntactically identifiable database calls. */
final class DatabaseTouchpoints
{
    public function extract(array $nodes): array
    {
        $finder = new NodeFinder();
        $calls = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\New_
            || $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\MethodCall);
        $findings = [];
        foreach ($calls as $call) {
            $api = null;
            $category = 'query';
            if ($call instanceof Node\Expr\New_ && $call->class instanceof Node\Name) {
                $class = strtolower($call->class->toString());
                if (in_array($class, ['pdo', 'mysqli'], true)) {
                    $api = $class . '::__construct';
                    $category = 'connection';
                }
            } elseif ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
                $name = strtolower($call->name->toString());
                if (in_array($name, ['mysqli_connect', 'mysqli_real_connect', 'mysqli_query', 'mysqli_prepare', 'mysqli_execute_query'], true)) {
                    $api = $name;
                    $category = in_array($name, ['mysqli_connect', 'mysqli_real_connect'], true) ? 'connection' : 'query';
                }
            } elseif ($call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier
                && $call->var instanceof Node\Expr\New_ && $call->var->class instanceof Node\Name) {
                $class = strtolower($call->var->class->toString());
                $method = strtolower($call->name->toString());
                if (($class === 'pdo' && in_array($method, ['query', 'prepare', 'exec'], true))
                    || ($class === 'mysqli' && in_array($method, ['query', 'prepare', 'real_query'], true))) {
                    $api = $class . '::' . $method;
                }
            }
            if ($api !== null) {
                $findings[] = ['api' => $api, 'category' => $category, 'confidence' => 'heuristic',
                    'line' => $call->getStartLine(), 'startOffset' => $call->getStartFilePos(), 'endOffset' => $call->getEndFilePos() + 1];
            }
        }
        $this->inspectStatements($nodes, $findings);
        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure) as $scope) {
            if (is_array($scope->stmts)) {
                $this->inspectStatements($scope->stmts, $findings);
            }
        }
        usort($findings, static fn (array $a, array $b): int => [$a['startOffset'], $a['endOffset'], $a['api']] <=> [$b['startOffset'], $b['endOffset'], $b['api']]);
        return $findings;
    }

    private function inspectStatements(array $statements, array &$findings): void
    {
        $bindings = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $this->inspectStatements($statement->stmts, $findings);
                $bindings = [];
                continue;
            }
            if (!$statement instanceof Node\Stmt\Expression) {
                $bindings = [];
                continue;
            }
            $expression = $statement->expr;
            $assigned = null;
            if ($expression instanceof Node\Expr\Assign && $expression->var instanceof Node\Expr\Variable && is_string($expression->var->name)) {
                $assigned = $expression->var->name;
                $expression = $expression->expr;
            }
            if ($expression instanceof Node\Expr\MethodCall && $expression->var instanceof Node\Expr\Variable
                && is_string($expression->var->name) && $expression->name instanceof Node\Identifier) {
                $class = $bindings[$expression->var->name] ?? null;
                $method = strtolower($expression->name->toString());
                if (($class === 'pdo' && in_array($method, ['query', 'prepare', 'exec'], true))
                    || ($class === 'mysqli' && in_array($method, ['query', 'prepare', 'real_query'], true))) {
                    $findings[] = ['api' => $class . '::' . $method, 'category' => 'query', 'confidence' => 'heuristic',
                        'line' => $expression->getStartLine(), 'startOffset' => $expression->getStartFilePos(), 'endOffset' => $expression->getEndFilePos() + 1];
                }
                // Any call can mutate values through arguments or references.
                $bindings = [];
            } elseif ($assigned !== null && $expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name) {
                $class = strtolower($expression->class->toString());
                unset($bindings[$assigned]);
                if (in_array($class, ['pdo', 'mysqli'], true)) {
                    $bindings[$assigned] = $class;
                }
            } else {
                $bindings = [];
            }
            if ($assigned !== null && !($expression instanceof Node\Expr\New_)) {
                unset($bindings[$assigned]);
            }
        }
    }
}
