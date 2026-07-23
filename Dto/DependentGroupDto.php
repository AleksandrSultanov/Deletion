<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final readonly class DependentGroupDto
{
    /**
     * @param list<int|string> $ids
     * @param string           $childClass
     * @param bool             $hard
     * @param int              $count
     * @param string|null      $field
     */
    public function __construct(
        public string $childClass,
        public bool $hard,
        public int $count,
        public array $ids,
        public ?string $field = null
    )
    {
    }
}
