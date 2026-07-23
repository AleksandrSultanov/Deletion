<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final readonly class DependentGroupDto
{
    /**
     * @param string           $childClass FQCN зависимой сущности
     * @param bool             $hard       блокирует ли группа удаление
     * @param int              $count      количество (инвариант: === count($ids))
     * @param list<int|string> $ids        идентификаторы зависимых записей
     * @param string|null      $field      поле-связка
     */
    public function __construct(
        public string $childClass,
        public bool $hard,
        public int $count,
        public array $ids,
        public ?string $field = null
    ) {
    }

    /**
     * Фабрика, гарантирующая инвариант count === count($ids) и list-семантику ids (F21).
     *
     * @param list<int|string> $ids
     */
    public static function of(string $childClass, bool $hard, array $ids, ?string $field = null): self
    {
        $ids = array_values($ids);

        return new self($childClass, $hard, count($ids), $ids, $field);
    }
}
