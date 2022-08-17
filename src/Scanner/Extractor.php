<?php

declare(strict_types=1);

namespace LegacyLens\Scanner;

use PhpParser\Error;
use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

final class Extractor
{
    public function extract(string $source): array
    {
        if (preg_match('/^\s*<\?hh(?:\s|$)/', $source) === 1) {
            return ['state' => 'failed', 'reason' => 'unsupported-hack-syntax', 'line' => 1, 'declarations' => [], 'references' => []];
        }
        $lexer = new Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos']]);
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $nodes = $parser->parse($source) ?? [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor(new ParentConnectingVisitor());
            $nodes = $traverser->traverse($nodes);
        } catch (Error $error) {
            // Parser messages can contain source text; report only the location.
            return ['state' => 'failed', 'line' => $error->getStartLine(), 'declarations' => [], 'references' => []];
        }

        $finder = new NodeFinder();
        $declarations = [];
        $references = [];
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
            if ($node->name !== null && isset($node->namespacedName)) {
                $kind = $node instanceof Node\Stmt\Class_ ? 'class' : ($node instanceof Node\Stmt\Interface_ ? 'interface' : ($node instanceof Node\Stmt\Trait_ ? 'trait' : 'enum'));
                $declarations[] = ['kind' => $kind, 'name' => $node->namespacedName->toString(), 'line' => $node->getStartLine(), 'conditional' => $this->isConditional($node)];
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $node) {
            $declarations[] = ['kind' => 'function', 'name' => $node->namespacedName->toString(), 'line' => $node->getStartLine()];
        }
        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\Include_) as $node) {
            if ($node instanceof Node\Expr\Include_) {
                $references[] = ['type' => 'include', 'name' => $node->expr instanceof Node\Scalar\String_ ? $node->expr->value : null, 'line' => $node->getStartLine(), 'startOffset' => $node->getStartFilePos(), 'endOffset' => $node->getEndFilePos() + 1];
                continue;
            }
            $references[] = ['type' => $node instanceof Node\Expr\New_ ? 'instantiation' : 'static-call', 'name' => $node->class instanceof Node\Name ? $node->class->toString() : null, 'line' => $node->getStartLine(), 'startOffset' => $node->getStartFilePos(), 'endOffset' => $node->getEndFilePos() + 1];
        }

        return ['state' => 'clean', 'declarations' => $declarations, 'references' => $references];
    }

    private function isConditional(Node $node): bool
    {
        $parent = $node->getAttribute('parent');
        // Only top-level declarations and namespace members are unconditional.
        return $parent !== null && !($parent instanceof Node\Stmt\Namespace_);
    }
}
