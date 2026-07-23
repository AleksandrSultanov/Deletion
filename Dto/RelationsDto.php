<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final readonly class RelationsDto
{
    /**
     * @param list<DependentGroupDto> $parents        родительские связи (всегда информационные, hard=false)
     * @param list<DependentGroupDto> $childrenDelete дети под удаление (жёсткие блокируют родителя)
     * @param list<DependentGroupDto> $childrenDetach связи M2M под отвязку (не блокируют родителя)
     * @param bool                    $canDelete      можно ли удалить родителя
     */
    public function __construct(
        public array $parents,
        public array $childrenDelete,
        public array $childrenDetach,
        public bool $canDelete
    ) {
    }
}
