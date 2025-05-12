<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Collects a small set of syntactic evidence; it never resolves runtime calls. */
final class MethodEvidence
{
    public function collect(array $nodes): array
    {
        $finder = new NodeFinder();
        $methods = [];
        $calls = [];
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $owner) {
            $kind = $this->ownerKind($owner);
            if ($kind === null || $owner->name === null || !isset($owner->namespacedName)) {
                continue;
            }
            foreach ($owner->getMethods() as $method) {
                $methods[] = [
                    'owner' => $owner->namespacedName->toString(),
                    'ownerKind' => $kind,
                    'ownerLine' => $owner->getStartLine(),
                    'name' => $method->name->toString(),
                    'line' => $method->name->getStartLine(),
                    'visibility' => $method->isPrivate() ? 'private' : ($method->isProtected() ? 'protected' : 'public'),
                    'static' => $method->isStatic(),
                    'startOffset' => $method->name->getStartFilePos(),
                    'endOffset' => $method->name->getEndFilePos() + 1,
                ];
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
            if ($call->isFirstClassCallable()) {
                continue;
            }
            $className = $call->class instanceof Node\Name ? $call->class->toString() : null;
            $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
            $calls[] = [
                'line' => $call->getStartLine(),
                'form' => 'static',
                'caller' => $this->caller($call),
                'className' => $className,
                'methodName' => $methodName,
                'target' => null,
                'confidence' => 'unresolved',
                'reason' => $className === null ? 'dynamic-class' : ($methodName === null ? 'dynamic-method' : 'static-target-unresolved'),
                'startOffset' => $call->getStartFilePos(),
                'endOffset' => $call->getEndFilePos() + 1,
            ];
        }
        return ['methods' => $methods, 'calls' => $calls];
    }

    private function ownerKind(Node\Stmt\ClassLike $owner): ?string
    {
        if ($owner instanceof Node\Stmt\Class_) return 'class';
        if ($owner instanceof Node\Stmt\Interface_) return 'interface';
        if ($owner instanceof Node\Stmt\Trait_) return 'trait';
        return null;
    }

    private function caller(Node $node): ?array
    {
        $current = $node->getAttribute('parent');
        while ($current !== null && !($current instanceof Node\Stmt\ClassMethod)) {
            if ($current instanceof Node\Stmt\Function_ || $current instanceof Node\Expr\Closure
                || $current instanceof Node\Expr\ArrowFunction || $current instanceof Node\Stmt\ClassLike) {
                return null;
            }
            $current = $current->getAttribute('parent');
        }
        if (!($current instanceof Node\Stmt\ClassMethod)) return null;
        $owner = $current->getAttribute('parent');
        if (!($owner instanceof Node\Stmt\ClassLike) || $this->ownerKind($owner) === null
            || $owner->name === null || !isset($owner->namespacedName)) return null;
        return ['owner' => $owner->namespacedName->toString(), 'method' => $current->name->toString()];
    }
}
