<?php

declare(strict_types=1);

namespace Shared\Deletion\Exception;

use RuntimeException;
use Shared\Deletion\Dto\RelationsDto;

/**
 * Бросается, когда {@see \Shared\Deletion\Service\DeletionOrchestrator::execute()} пытается удалить
 * сущность, которую анализ пометил неудаляемой (есть жёсткие блокирующие зависимости).
 *
 * Восстанавливает контракт блокировки на исполняющем пути (F1). Несёт результат анализа, чтобы
 * вызывающий код мог показать пользователю, что именно мешает удалению.
 */
final class DeletionBlockedException extends RuntimeException
{
    public function __construct(
        public readonly RelationsDto $relations,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : 'Удаление заблокировано жёсткими зависимостями.');
    }
}
