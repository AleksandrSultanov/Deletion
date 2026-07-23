# Shared\Deletion

Библиотека для **анализа и каскадного удаления Doctrine-сущностей на основе атрибутов**.
Отвечает на два вопроса:

1. «Можно ли удалить эту сущность?» — `DeletionService::canDelete()` / `analyze()`.
2. «Как удалить её вместе с зависимостями в правильном порядке?» — `DeletionOrchestrator::execute()`.

## Модель: две ортогональные оси

Атрибут `#[RelationTo]` вешается на **дочернюю** сущность и описывает её связь с родителем.
Поведение задаётся двумя независимыми enum'ами — их **нельзя смешивать**:

- **`RelationType`** (`type`) — блокирует ли *наличие* связи удаление родителя:
  - `BLOCKING` — пока связь существует, **родителя** удалить нельзя;
  - `REFERENCE` — просто ссылка, ничего не блокирует.
- **`DeletionCascade`** (`cascade`) — что делать с ребёнком при удалении родителя:
  - `NONE` — ребёнка не трогать;
  - `DELETE_CHILD` — удалить ребёнка вместе с родителем;
  - `DETACH_RELATIONS` — удалить только строки в join-таблице (сам ребёнок остаётся).

> **Направление важно:** `BLOCKING` на дочерней сущности означает «нельзя удалить **РОДИТЕЛЯ**»,
> а не саму дочернюю сущность. `DETACH_RELATIONS`-связи никогда не блокируют удаление родителя.

## Параметры атрибута `RelationTo`

| Параметр | Тип | Обяз. | Описание |
|---|---|---|---|
| `entity` | `class-string` | да | FQCN родительской сущности |
| `field` | `string` | да | Имя поля-связки (для M2M — формально, обычно `'id'`) |
| `type` | `RelationType` | нет | Блокировка удаления родителя. По умолчанию `REFERENCE` |
| `cascade` | `DeletionCascade` | нет | Каскад при удалении родителя. По умолчанию `NONE` |
| `joinTable` | `?string` | нет | Промежуточная таблица M2M |
| `joinColumn` | `?string` | нет | Колонка M2M-таблицы, ссылающаяся на родителя |
| `inverseJoinColumn` | `?string` | нет | Колонка M2M-таблицы, ссылающаяся на ребёнка |

`joinTable`, `joinColumn` и `inverseJoinColumn` задаются **только все вместе** — иначе конструктор
атрибута бросит `InvalidArgumentException`.

## Использование

### 1. Аннотирование зависимостей

```php
<?php

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\DeletionCascade;
use Shared\Deletion\Enum\RelationType;
use Domain\Order\Common\Entity\OrderEntity;

// OrderItem нельзя удалить свободно родителю, пока он есть (BLOCKING),
// и он удаляется вместе с заказом (DELETE_CHILD).
#[RelationTo(
    entity: OrderEntity::class,
    field: 'order',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DELETE_CHILD
)]
final class OrderItemEntity
{
    private OrderEntity $order;
}
```

Сущность может зависеть от нескольких родителей — атрибут `IS_REPEATABLE`:

```php
#[RelationTo(entity: OrderEntity::class, field: 'order', type: RelationType::BLOCKING)]
#[RelationTo(entity: ProductEntity::class, field: 'product', type: RelationType::REFERENCE)]
final class OrderItemEntity { /* ... */ }
```

### 2. Проверка возможности удаления

```php
<?php

use Shared\Deletion\DeletionService;

final class SomeController
{
    public function __construct(private DeletionService $deletionService) {}

    public function checkDelete(OrderEntity $order): Response
    {
        $result = $this->deletionService->canDelete($order);

        if (!$result->canDelete) {
            foreach ($result->dependents as $group) {
                // $group->childClass, $group->count, $group->hard, $group->ids
            }
        }

        return new JsonResponse([
            'canDelete' => $result->canDelete,
            'dependents' => $result->dependents,
        ]);
    }
}
```

### 3. Каскадное удаление

```php
<?php

use Shared\Deletion\Service\DeletionOrchestrator;
use Shared\Deletion\Exception\DeletionBlockedException;

/** @var DeletionOrchestrator $orchestrator */

// Обычное удаление: бросит DeletionBlockedException, если есть жёсткие блокирующие зависимости.
try {
    $orchestrator->execute($order);
} catch (DeletionBlockedException $e) {
    $relations = $e->relations; // что именно мешает удалению
}

// Прогон без мутаций (SQL/remove не выполняются, метрики dry-run не учитывают как реальные):
$orchestrator->execute($order, dryRun: true);

// Принудительное удаление в обход блокировки:
$orchestrator->execute($order, force: true);
```

Порядок исполнения в одной транзакции: **1) detach M2M → 2) удаление детей (топологически, сначала
самые глубокие уровни) → 3) удаление корня**. Обход защищён от циклов, дети грузятся батчем на класс.

### 4. Связи через промежуточную таблицу (Many-to-Many)

```php
#[RelationTo(
    entity: AdvertEntity::class,
    field: 'id',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DETACH_RELATIONS,
    joinTable: 'advert_tag_relation',
    joinColumn: 'advert_id',
    inverseJoinColumn: 'advert_tag_id'
)]
final class AdvertTagEntity {}
```

- При проверке `AdvertTag` — если в `advert_tag_relation` есть строки с этим тегом, тег удалить нельзя.
- При удалении `Advert` — связи в join-таблице отвязываются, сам `AdvertTag` остаётся.

## Middleware

`DeletionOrchestrator` уведомляет middleware (`DeletionMiddlewareInterface`) на каждой фазе
(`before/after` detach/deleteChildren/deleteRoot). Исключение в middleware не срывает удаление, но
логируется (если оркестратору передан `Psr\Log\LoggerInterface`). Флаг `supports()` проверяется один раз
по корню операции; флаг `$dryRun` передаётся во все хуки.

Готовые реализации:

- `LoggingDeletionMiddleware` — журналирование фаз (PSR-3).
- `MetricsDeletionMiddleware` — тайминги фаз/операции и счётчики объёма (см. `MetricsCollectorInterface`).

Для реализации подмножества хуков подключите трейт `DeletionMiddlewareDefaults` (no-op по умолчанию).

## Зависимости

Это фрагмент большого Symfony/Doctrine-проекта. Внешние зависимости:

- `Doctrine\ORM\EntityManagerInterface` и метаданные Doctrine ORM;
- `Shared\Persistence\GenericReadRepository` — репозиторий с методами `getId()`, `findByAssociation()`,
  `findByJoinTable()`, `findByJsonContains()`;
- `Psr\Log\LoggerInterface` (опционально).

## Тесты

```bash
composer install
php vendor/bin/phpunit
```
