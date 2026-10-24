<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use PhpParser\Node;

/** Extracts only methods directly declared on named PHP types. */
final class MethodDeclarations
{
    public function extract(Node\Stmt\ClassLike $owner, string $kind): array
    {
        if ($owner->name === null || !isset($owner->namespacedName)) {
            return [];
        }
        $methods = [];
        foreach ($owner->getMethods() as $method) {
            $name = $method->name;
            $methods[] = [
                'owner' => $owner->namespacedName->toString(),
                'ownerKind' => $kind,
                'ownerLine' => $owner->getStartLine(),
                'name' => $name->toString(),
                'line' => $name->getStartLine(),
                'visibility' => $method->isPrivate() ? 'private' : ($method->isProtected() ? 'protected' : 'public'),
                'static' => $method->isStatic(),
                'startOffset' => $name->getStartFilePos(),
                'endOffset' => $name->getEndFilePos() + 1,
            ];
        }
        return $methods;
    }
}
