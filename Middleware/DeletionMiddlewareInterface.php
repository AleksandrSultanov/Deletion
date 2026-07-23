<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

interface DeletionMiddlewareInterface
{
    public function supports(string $entityClass): bool;

    /**
     * @param array<int|string> $childIds
     * @param string            $parentClass
     * @param string            $childClass
     * @param array             $relation
     * @param object            $root
     */
    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void;

    /**
     * @param array<int|string> $childIds
     * @param string            $parentClass
     * @param string            $childClass
     * @param array             $relation
     * @param object            $root
     */
    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void;

    /**
     * @param array<int|string> $childIds
     * @param string            $childClass
     * @param object            $root
     */
    public function beforeDeleteChildren(string $childClass, array $childIds, object $root): void;

    /**
     * @param array<int|string> $childIds
     * @param string            $childClass
     * @param object            $root
     */
    public function afterDeleteChildren(string $childClass, array $childIds, object $root): void;

    public function beforeDeleteRoot(object $root): void;

    public function afterDeleteRoot(object $root): void;
}
