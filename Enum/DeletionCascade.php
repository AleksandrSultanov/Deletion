<?php

declare(strict_types=1);

namespace Shared\Deletion\Enum;

enum DeletionCascade: string
{
    case NONE = 'none';               // не удалять ребенка при удалении родителя
    case DELETE_CHILD = 'delete';     // удалить ребенка вместе с родителем
    case DETACH_RELATIONS = 'detach'; // удалить только связи (join table), сам ребенок не удаляется
}
