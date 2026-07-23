<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

use Shared\Deletion\Enum\DeletionCascade;

/**
 * Правило связи «родитель → ребёнок» из карты {@see \Shared\Deletion\DeletionService}.
 *
 * Заменяет прежний позиционный кортеж array{0:string,1:string,...} — устраняет класс ошибок
 * «перепутан индекс» и самодокументирует правило (F19, инкапсуляция карты).
 */
final readonly class RelationRule
{
    public function __construct(
        public string $childClass,
        public string $field,
        /** BLOCKING на ребёнке ⇒ блокирует удаление РОДИТЕЛЯ. */
        public bool $isBlocking,
        public DeletionCascade $cascade,
        public ?string $joinTable = null,
        public ?string $joinColumn = null,
        public ?string $inverseJoinColumn = null,
    ) {
    }

    /** Является ли связь many-to-many через промежуточную таблицу. */
    public function isJoinTable(): bool
    {
        return $this->joinTable !== null
            && $this->joinColumn !== null
            && $this->inverseJoinColumn !== null;
    }
}
