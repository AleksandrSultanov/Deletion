<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final class OrderedPlanDto
{
    /**
     * @param list<array{class:string, ids:array}> $delete @param list<array{joinTable:string, joinColumn:string, inverseJoinColumn:string, parentId:int|string, childClass:string, childIds:array}> $detach
     * @param array                                $detach
     */
    public function __construct(
        public readonly array $delete,
        public readonly array $detach
    )
    {
    }
}
