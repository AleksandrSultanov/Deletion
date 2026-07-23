<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

/**
 * Хуки жизненного цикла каскадного удаления.
 *
 * Флаг $dryRun сообщает, что операция «прогонная» — реальных мутаций не будет (F10): наблюдатели
 * (метрики/аудит) не должны учитывать её как настоящее удаление.
 *
 * Для реализации подмножества хуков используйте {@see DeletionMiddlewareDefaults}.
 */
interface DeletionMiddlewareInterface
{
    /** Применим ли middleware к операции удаления с данным корнем. Проверяется оркестратором один раз. */
    public function supports(string $entityClass): bool;

    /**
     * @param list<int|string>                                                                                    $childIds
     * @param array{joinTable: string, joinColumn: string, inverseJoinColumn: string, parentId: int|string, childClass: string, childIds: list<int|string>} $relation
     */
    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void;

    /**
     * @param list<int|string>                                                                                    $childIds
     * @param array{joinTable: string, joinColumn: string, inverseJoinColumn: string, parentId: int|string, childClass: string, childIds: list<int|string>} $relation
     */
    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void;

    /**
     * @param list<int|string> $childIds
     */
    public function beforeDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void;

    /**
     * @param list<int|string> $childIds
     */
    public function afterDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void;

    public function beforeDeleteRoot(object $root, bool $dryRun): void;

    public function afterDeleteRoot(object $root, bool $dryRun): void;
}
