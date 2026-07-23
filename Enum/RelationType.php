<?php

declare(strict_types=1);

namespace Shared\Deletion\Enum;

enum RelationType: string
{
    case BLOCKING = 'blocking';     // наличие связи блокирует удаление текущей сущности
    case REFERENCE = 'reference';   // просто связь (для каскадного удаления родителя), не блокирует текущую
}
