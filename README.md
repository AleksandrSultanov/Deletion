# Deletion Service

Сервис для проверки возможности удаления сущностей и получения списка зависимых объектов.

## Использование

### 1. Аннотирование зависимостей

На сущности-"ребенке" указываем от какой сущности она зависит:

```php
<?php

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;
use Domain\Order\Common\Entity\OrderEntity;

#[RelationTo(entity: OrderEntity::class, field: 'order', type: RelationType::BLOCKING)]
final class OrderItemEntity
{
    // поле order должно ссылаться на OrderEntity
    private OrderEntity $order;
    
    // OrderItemEntity нельзя удалить, если она связана с OrderEntity
    // OrderEntity можно удалить свободно
}
```

### 2. Проверка возможности удаления

```php
<?php

use Shared\Deletion\DeletionService;

class SomeController
{
    public function __construct(
        private DeletionService $deletionService
    ) {}
    
    public function checkDelete(OrderEntity $order): Response
    {
        $result = $this->deletionService->canDelete($order);
        
        if (!$result->canDelete) {
            // Есть зависимые сущности, которые блокируют удаление
            foreach ($result->dependents as $group) {
                echo "Найдено {$group->count} записей типа {$group->childClass}";
                if ($group->hard) {
                    echo " (блокирует удаление)";
                }
                echo "\n";
            }
        }
        
        return new JsonResponse([
            'canDelete' => $result->canDelete,
            'dependents' => $result->dependents
        ]);
    }
}
```

### 3. Параметры атрибута DependsOn

- `parent` - FQCN родительской сущности (обязательный)
- `field` - имя поля в дочерней сущности, которое ссылается на родителя (обязательный)
- `hard` - если true, блокирует удаление родителя (по умолчанию true)
- `joinTable` - имя промежуточной таблицы для many-to-many связей (опциональный)
- `joinColumn` - колонка в промежуточной таблице, которая ссылается на родителя (опциональный)
- `inverseJoinColumn` - колонка в промежуточной таблице, которая ссылается на ребенка (опциональный)

### 4. Связи через промежуточные таблицы (Many-to-Many)

Для связей через промежуточную таблицу укажите дополнительные параметры:

```php
<?php

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;
use Domain\Advert\Common\Entity\AdvertEntity;

#[RelationTo(
    entity: AdvertEntity::class,
    field: 'id', // формально обязательное поле
    type: RelationType::BLOCKING,
    joinTable: 'advert_tag_relation', // имя промежуточной таблицы
    joinColumn: 'advert_id',          // колонка, ссылающаяся на родителя
    inverseJoinColumn: 'advert_tag_id' // колонка, ссылающаяся на текущую сущность
)]
final class AdvertTagEntity
{
    // AdvertTagEntity нельзя удалить, если есть связи в таблице advert_tag_relation
    // AdvertEntity можно удалить свободно
}
```

**Логика работы:**

- При проверке `AdvertTag` - система проверит есть ли записи в `advert_tag_relation` где `advert_tag_id` = ID тега. Если
  есть - тег нельзя удалить
- При проверке `Advert` - система НЕ блокирует удаление родителя. Advert можно удалить свободно

### 5. Множественные зависимости

Сущность может зависеть от нескольких родителей:

```php
<?php

#[RelationTo(entity: OrderEntity::class, field: 'order', type: RelationType::BLOCKING)]
#[RelationTo(entity: ProductEntity::class, field: 'product', type: RelationType::REFERENCE)]
final class OrderItemEntity
{
    private OrderEntity $order;
    private ProductEntity $product;
    
    // ...
}
```

## Консольная команда

Для проверки зависимостей из командной строки используйте команду:

```bash
# По короткому имени сущности
php bin/console deletion:check OrderEntity 123

# По полному FQCN
php bin/console deletion:check "Domain\Order\Common\Entity\OrderEntity" 123
```

Команда выведет:

- Можно ли удалить сущность
- Список всех зависимых объектов с количеством
- Тип зависимости (блокирующая или мягкая)
- ID первых 5 зависимых записей
