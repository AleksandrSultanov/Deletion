<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

/**
 * No-op реализация всех хуков {@see DeletionMiddlewareInterface}.
 *
 * Смягчает «толстый» интерфейс (ISP, F11): middleware, которому нужны один-два хука, может
 * подключить трейт и переопределить только их, не реализуя весь набор вручную.
 */
trait DeletionMiddlewareDefaults
{
    public function supports(string $entityClass): bool
    {
        return true;
    }

    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void
    {
    }

    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void
    {
    }

    public function beforeDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void
    {
    }

    public function afterDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void
    {
    }

    public function beforeDeleteRoot(object $root, bool $dryRun): void
    {
    }

    public function afterDeleteRoot(object $root, bool $dryRun): void
    {
    }
}
