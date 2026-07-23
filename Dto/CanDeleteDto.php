<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final readonly class CanDeleteDto
{
    /**
     * @param list<DependentGroupDto> $dependents
     * @param bool                    $canDelete
     */
    public function __construct(
        public bool $canDelete,
        public array $dependents
    )
    {
    }
}
