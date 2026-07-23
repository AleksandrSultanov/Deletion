<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

use Psr\Log\LoggerInterface;

final class LoggingDeletionMiddleware implements DeletionMiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function supports(string $entityClass): bool
    {
        return true;
    }

    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $this->logger->info('Detach relations', compact('parentClass', 'childClass', 'childIds', 'relation'));
    }

    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $this->logger->info('Detached relations', compact('parentClass', 'childClass', 'childIds', 'relation'));
    }

    public function beforeDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $this->logger->info('Delete children', compact('childClass', 'childIds'));
    }

    public function afterDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $this->logger->info('Deleted children', compact('childClass', 'childIds'));
    }

    public function beforeDeleteRoot(object $root): void
    {
        $this->logger->info('Delete root start', ['class' => $root::class]);
    }

    public function afterDeleteRoot(object $root): void
    {
        $this->logger->info('Delete root done', ['class' => $root::class]);
    }
}
