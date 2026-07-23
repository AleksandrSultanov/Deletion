<?php

declare(strict_types=1);

namespace Shared\Deletion\Dto;

final readonly class OrderedPlanDto
{
    /**
     * @param list<array{class: string, ids: list<int|string>, idField: string}>                                                            $delete шаги удаления, топологически упорядоченные (сначала листья)
     * @param list<array{joinTable: string, joinColumn: string, inverseJoinColumn: string, parentId: int|string, childClass: string, childIds: list<int|string>}> $detach шаги отвязки M2M
     */
    public function __construct(
        public array $delete,
        public array $detach
    ) {
    }
}
