<?php

declare(strict_types=1);

namespace Shared\Deletion\Attribute;

use Attribute;
use InvalidArgumentException;
use Shared\Deletion\Enum\DeletionCascade;
use Shared\Deletion\Enum\RelationType;

/**
 * Описывает связь дочерней сущности с родителем (см. CLAUDE.md — две ортогональные оси: type/cascade).
 *
 * @see RelationType    блокирует ли наличие связи удаление родителя
 * @see DeletionCascade что делать с ребёнком при удалении родителя
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class RelationTo
{
    /**
     * @param class-string $entity FQCN родительской сущности
     *
     * @throws InvalidArgumentException при несогласованной конфигурации (F16)
     */
    public function __construct(
        public readonly string $entity,
        public readonly string $field,
        public readonly RelationType $type = RelationType::REFERENCE,
        public readonly DeletionCascade $cascade = DeletionCascade::NONE,
        public readonly ?string $joinTable = null,
        public readonly ?string $joinColumn = null,
        public readonly ?string $inverseJoinColumn = null
    ) {
        if ($this->entity === '') {
            throw new InvalidArgumentException('RelationTo: entity не может быть пустым.');
        }

        if ($this->field === '') {
            throw new InvalidArgumentException('RelationTo: field не может быть пустым.');
        }

        // joinTable / joinColumn / inverseJoinColumn имеют смысл только вместе.
        $joinPartsSet = count(array_filter(
            [$this->joinTable, $this->joinColumn, $this->inverseJoinColumn],
            static fn (?string $v): bool => $v !== null
        ));
        if ($joinPartsSet !== 0 && $joinPartsSet !== 3) {
            throw new InvalidArgumentException(
                'RelationTo: joinTable, joinColumn и inverseJoinColumn должны быть заданы вместе.'
            );
        }
    }
}
