<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final readonly class CanDeleteDto
{
    /**
     * @param bool                    $canDelete  можно ли удалить сущность
     * @param list<DependentGroupDto> $dependents все зависимости (parents + childrenDelete + childrenDetach)
     */
    public function __construct(
        public bool $canDelete,
        public array $dependents
    ) {
    }
}
