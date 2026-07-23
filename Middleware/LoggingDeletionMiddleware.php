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

    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void
    {
        $this->logger->info('Detach relations', $this->detachContext($parentClass, $childClass, $childIds, $root, $dryRun));
    }

    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void
    {
        $this->logger->info('Detached relations', $this->detachContext($parentClass, $childClass, $childIds, $root, $dryRun));
    }

    public function beforeDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void
    {
        $this->logger->info('Delete children', $this->childrenContext($childClass, $childIds, $root, $dryRun));
    }

    public function afterDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void
    {
        $this->logger->info('Deleted children', $this->childrenContext($childClass, $childIds, $root, $dryRun));
    }

    public function beforeDeleteRoot(object $root, bool $dryRun): void
    {
        $this->logger->info('Delete root start', ['root' => $root::class, 'dryRun' => $dryRun]);
    }

    public function afterDeleteRoot(object $root, bool $dryRun): void
    {
        $this->logger->info('Delete root done', ['root' => $root::class, 'dryRun' => $dryRun]);
    }

    /**
     * @param list<int|string> $childIds
     *
     * @return array<string, mixed>
     */
    private function detachContext(string $parentClass, string $childClass, array $childIds, object $root, bool $dryRun): array
    {
        return [
            'root' => $root::class,
            'parentClass' => $parentClass,
            'childClass' => $childClass,
            // Логируем количество, а не весь список id — чтобы не раздувать лог на массовых операциях.
            'childCount' => count($childIds),
            'dryRun' => $dryRun,
        ];
    }

    /**
     * @param list<int|string> $childIds
     *
     * @return array<string, mixed>
     */
    private function childrenContext(string $childClass, array $childIds, object $root, bool $dryRun): array
    {
        return [
            'root' => $root::class,
            'childClass' => $childClass,
            'childCount' => count($childIds),
            'dryRun' => $dryRun,
        ];
    }
}
