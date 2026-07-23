<?php

declare(strict_types=1);

namespace Shared\Deletion\Attribute;

use Attribute;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class RelationTo
{
    public function __construct(
        public readonly string $entity,
        public readonly string $field,
        public readonly RelationType $type = RelationType::REFERENCE,
        public readonly DeletionCascade $cascade = DeletionCascade::NONE,
        public readonly ?string $joinTable = null,
        public readonly ?string $joinColumn = null,
        public readonly ?string $inverseJoinColumn = null
    )
    {
    }
}
