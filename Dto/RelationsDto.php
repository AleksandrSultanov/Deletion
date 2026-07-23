<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final class RelationsDto
{
    /**
     * @param list<DependentGroupDto> $parents
     * @param list<DependentGroupDto> $childrenDelete
     * @param list<DependentGroupDto> $childrenDetach
     * @param bool                    $canDelete
     */
    public function __construct(
        public readonly array $parents,
        public readonly array $childrenDelete,
        public readonly array $childrenDetach,
        public readonly bool $canDelete
    )
    {
    }
}
