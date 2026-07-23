<?php

declare(strict_types=1);

namespace Shared\Persistence;

/**
 * Тестовый стаб реальной `Shared\Persistence\GenericReadRepository`, которая не входит в переданный
 * фрагмент библиотеки. Нужен только для того, чтобы PHPUnit мог создать мок с корректным FQCN
 * (createMock требует существующий класс/интерфейс).
 *
 * Сигнатуры соответствуют вызовам из `DeletionService`:
 *   - getId()             — DeletionService.php
 *   - findByAssociation() — прямая ссылка (scalar FK у ребёнка)
 *   - findByJoinTable()   — связь через промежуточную таблицу
 *   - findByJsonContains() — поиск по JSON-массиву
 *
 * В боевом коде это настоящая реализация; здесь методы намеренно не реализованы.
 */
class GenericReadRepository
{
    public function getId(object $object): int|string
    {
        throw new \LogicException('stub');
    }

    /**
     * @return iterable<object>
     */
    public function findByAssociation(string $entityClass, string $field, object $parent): iterable
    {
        throw new \LogicException('stub');
    }

    /**
     * @return iterable<object>
     */
    public function findByJoinTable(
        string $entityClass,
        string $joinTable,
        string $joinColumn,
        string $inverseJoinColumn,
        object $parent
    ): iterable {
        throw new \LogicException('stub');
    }

    /**
     * @return iterable<object>
     */
    public function findByJsonContains(string $entityClass, string $field, int|string $value): iterable
    {
        throw new \LogicException('stub');
    }
}
