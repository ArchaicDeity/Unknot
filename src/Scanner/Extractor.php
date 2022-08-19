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
            $reference = ['type' => $node instanceof Node\Expr\New_ ? 'instantiation' : 'static-call', 'name' => $node->class instanceof Node\Name ? $node->class->toString() : null, 'line' => $node->getStartLine(), 'startOffset' => $node->getStartFilePos(), 'endOffset' => $node->getEndFilePos() + 1];
            if ($node instanceof Node\Expr\New_) {
                $reference['expectedKind'] = 'class';
            }
            $references[] = $reference;
        }

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $node) {
            if ($node->extends !== null) {
                $references[] = $this->namedReference('extends', $node->extends, 'class');
            }
            foreach ($node->implements as $name) {
                $references[] = $this->namedReference('implements', $name, 'interface');
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Interface_::class) as $node) {
            foreach ($node->extends as $name) {
                $references[] = $this->namedReference('extends', $name, 'interface');
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Enum_::class) as $node) {
            foreach ($node->implements as $name) {
                $references[] = $this->namedReference('implements', $name, 'interface');
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\TraitUse::class) as $node) {
            foreach ($node->traits as $name) {
                $references[] = $this->namedReference('trait-use', $name, 'trait');
            }
        }

        return ['state' => 'clean', 'declarations' => $declarations, 'references' => $references];
    }

    private function namedReference(string $type, Node\Name $name, string $expectedKind): array
    {
        return ['type' => $type, 'name' => $name->toString(), 'expectedKind' => $expectedKind, 'line' => $name->getStartLine(), 'startOffset' => $name->getStartFilePos(), 'endOffset' => $name->getEndFilePos() + 1];
    }

    private function isConditional(Node $node): bool
    {
        $parent = $node->getAttribute('parent');
        // Only top-level declarations and namespace members are unconditional.
        return $parent !== null && !($parent instanceof Node\Stmt\Namespace_);
    }
}
