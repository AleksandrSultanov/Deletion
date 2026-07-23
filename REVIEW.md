# Code Review — модуль `Shared\Deletion`

Ревью фрагмента внутренней библиотеки удаления сущностей (Symfony + Doctrine ORM).
Модуль решает две задачи: **анализ** связей сущности через PHP-атрибуты (`DeletionService`)
и **каскадное исполнение** удаления с хуками-middleware внутри транзакции (`DeletionOrchestrator`).

Ссылки на строки даны по версии кода на момент ревью.

---

## Оглавление

1. [Общая оценка](#1-общая-оценка)
2. [Архитектура: что сделано хорошо](#2-архитектура-что-сделано-хорошо)
3. [Баги и необработанные edge cases](#3-баги-и-необработанные-edge-cases)
4. [Производительность](#4-производительность)
5. [SOLID / PSR / Symfony best practices](#5-solid--psr--symfony-best-practices)
6. [Что бы я сделал иначе (и почему)](#6-что-бы-я-сделал-иначе-и-почему)
7. [Сводная таблица находок](#7-сводная-таблица-находок)

---

## 1. Общая оценка

Модуль спроектирован по здравой идее: декларативное описание связей рядом с сущностью и
разделение «анализ / исполнение». Код читаемый, использует современный PHP 8.1+ (enum, readonly,
атрибуты, named arguments). Однако между **заявленным контрактом** (README, DTO, интерфейс middleware)
и **фактическим поведением** есть заметные расхождения, а исполняющая часть (`DeletionOrchestrator`)
содержит несколько потенциально критичных дефектов: отсутствие защиты от циклов, N+1, неверный порядок
удаления и — главное — **игнорирование результата анализа `canDelete` при реальном удалении**.

Резюме: аналитическая часть близка к production-качеству (после правки нескольких edge cases),
исполняющая — требует доработки перед боевым использованием.

---

## 2. Архитектура: что сделано хорошо

- **Декларативность.** Атрибут `RelationTo` (`Attribute/RelationTo.php`) с `Attribute::IS_REPEATABLE`
  позволяет описывать связи прямо на сущности, в т.ч. несколько на одном классе. Это читаемо и держит
  знание о связях рядом с моделью.
- **Разделение ответственности верхнего уровня.** `DeletionService` (анализ, «что будет затронуто»)
  и `DeletionOrchestrator` (исполнение) — правильная граница. Анализ можно переиспользовать в UI
  («покажи, что удалится») без риска что-либо удалить.
- **Middleware-паттерн.** Фазовые хуки `before/after` для detach / deleteChildren / deleteRoot дают
  расширяемость (логирование, метрики, аудит) без изменения ядра — открыто к расширению.
- **`dryRun`.** `DeletionOrchestrator::execute(object $root, bool $dryRun = false)` — хорошая практика:
  можно прогнать план и хуки без реальных мутаций.
- **Типобезопасные перечисления и иммутабельные DTO.** `RelationType` / `DeletionCascade` вместо
  «магических строк», `readonly`-DTO (`CanDeleteDto`, `DependentGroupDto`) — предсказуемость и отсутствие
  случайных мутаций.

---

## 3. Баги и необработанные edge cases

### 3.1. `execute()` игнорирует `canDelete` — «BLOCKING» фактически не блокирует (критично)

`DeletionService::analyze()` аккуратно вычисляет `canDelete` (учитывая жёсткие связи), но
`DeletionOrchestrator::execute()` (`Service/DeletionOrchestrator.php:28`) строит план и удаляет,
**не сверяясь с этим флагом**:

```php
public function execute(object $root, bool $dryRun = false): void
{
    $relations = $this->plan($root);          // analyze() посчитал canDelete...
    $plan = $this->buildOrderedPlan($root, $relations);  // ...но он здесь не проверяется
    $this->em->wrapInTransaction(function () ... { /* удаляем */ });
}
```

**Почему плохо:** сущность с блокирующей зависимостью, которую `canDelete` пометил как неудаляемую,
всё равно будет удалена. Контракт «BLOCKING блокирует удаление» нарушен на исполняющем пути.

**Как иначе:** в начале `execute()` — `if (!$relations->canDelete) { throw new DeletionBlockedException($relations); }`,
либо явный флаг `force`, чтобы обойти блокировку осознанно.

### 3.2. Нет защиты от циклов и диамантов в рекурсии (критично)

`DeletionOrchestrator::buildRecursive()` (`Service/DeletionOrchestrator.php:89`) обходит дерево детей
рекурсивно, но не ведёт множество «посещённых» узлов:

```php
foreach ($group->ids as $cid) {
    $child = /* load */;
    $childRelations = $this->analyzer->analyze($child);
    $this->buildRecursive($child, $childRelations, $deleteMap, $detach); // нет visited-set
}
```

**Почему плохо:** циклическая связь (A → B → A) даёт **бесконечную рекурсию** и падение по памяти/времени;
диамант (A → B, A → C, B → D, C → D) приводит к повторному обходу поддерева `D`. `deleteMap` дедуплицирует
*id для удаления*, но не защищает *сам обход*.

**Как иначе:** держать `array<string,bool> $visited` по ключу `class#id` и выходить, если узел уже посещён.

### 3.3. `getJoinTableParentIds()` шлёт DQL по имени таблицы, а не сущности

`DeletionService::getJoinTableParentIds()` (`DeletionService.php:211`):

```php
$qb->select("jt.{$joinColumn}")
   ->from($joinTable, 'jt')   // $joinTable — имя M2M-таблицы, а from() ждёт FQCN сущности
```

**Почему плохо:** join-таблица many-to-many — это не Doctrine-entity. DQL-парсер попытается разрешить
`$joinTable` как имя сущности и упадёт (`Class ... is not defined`). Ветка нерабочая для реального M2M.

**Как иначе:** использовать native SQL через DBAL — ровно как уже сделано в `detachJoinRow()`
(`DeletionOrchestrator.php:168`), где по join-таблице идёт корректный `$conn->executeStatement(...)`.

### 3.4. `getScalarFkValue()` читает свойство-ассоциацию как скаляр

`DeletionService::getScalarFkValue()` (`DeletionService.php:227`) через Reflection читает значение поля
и трактует его как `int|string`. Но по README (пример `OrderItemEntity`) поле `order` имеет тип
`OrderEntity` — то есть Reflection вернёт **объект**, а не id:

```php
$parentId = $this->getScalarFkValue($object, $attribute->field); // может быть объект OrderEntity
if ($parentId !== null && $parentId !== 0 && $parentId !== '') {
    $dependencies[] = new DependentGroupDto($attribute->entity, $isBlocking, 1, [$parentId], ...);
    // в ids попадёт объект вместо id
}
```

**Почему плохо:** в `DependentGroupDto::ids` попадёт сущность вместо скалярного id; сериализация/сравнение
сломаются. Атрибут не различает «скалярный FK» и «ассоциация-объект».

**Как иначе:** определять по метаданным Doctrine (`isAssociation`/`fieldMappings`): для ассоциации — брать
id связанной сущности (`$em->getClassMetadata()->getIdentifierValues($related)`), для скаляра — значение.

### 3.5. `notify()` молча глотает все исключения middleware

`DeletionOrchestrator::notify()` (`Service/DeletionOrchestrator.php:156`):

```php
foreach ($this->middlewares as $mw) {
    if (method_exists($mw, $method)) {
        try { $mw->{$method}(...$args); } catch (Throwable) {} // всё проглочено
    }
}
```

**Почему плохо:** это происходит внутри транзакции удаления. Любая ошибка в middleware (в т.ч. симптом
реального бага, а не «наблюдателя») исчезает бесследно — нет ни лога, ни метрики о самой потере.
Для метрик/аудита это означает тихую потерю данных наблюдаемости.

**Как иначе:** дифференцировать политику: «наблюдательные» middleware (лог, метрики) — глушить, но
логировать сам факт исключения; критичные — прокидывать. Как минимум — прокинуть в переданный логгер.

### 3.6. `supports()` объявлен в интерфейсе, но не вызывается

`DeletionMiddlewareInterface::supports(string $entityClass): bool` (`Middleware/DeletionMiddlewareInterface.php:9`)
нигде в оркестраторе не используется — вместо контракта применяется `method_exists()`.

**Почему плохо:** мёртвый контракт вводит в заблуждение (реализующий думает, что `supports()` фильтрует
вызовы — а он игнорируется). Нарушение ISP: интерфейс требует метод, который система не соблюдает.

**Как иначе:** либо вызывать `supports($root::class)` перед `notify`, либо убрать метод из интерфейса.

### 3.7. Порядок удаления не топологический

`buildOrderedPlan()` (`Service/DeletionOrchestrator.php:67`) складывает удаляемые сущности в `deleteMap`,
ключ — класс, порядок — порядок вставки при обходе сверху вниз. Затем `deleteByIds()` выполняется в этом
же порядке.

**Почему плохо:** при наличии FK-ограничений между уровнями дерева удаление «сверху вниз» может нарушить
целостность (родительский уровень удаляется раньше внуков). Нужен обход/удаление **снизу вверх**
(сначала листья).

**Как иначе:** топологическая сортировка удаляемых классов/записей по глубине, удаление от листьев к корню.

### 3.8. JSON-ветка: теряется id `0`, слабый фильтр, нарушение `list`

`getJsonArrayParentIds()` (`DeletionService.php:173`):

- `if (empty($jsonValue)) return [];` — `empty('0')` и `empty(0)` истинны, валидный id `0` отбрасывается.
- `array_filter($jsonValue, fn($id) => is_numeric($id) || is_string($id))` — предикат почти всегда true,
  фильтрация бессмысленна; к тому же `array_filter` **сохраняет ключи**, результат перестаёт быть `list`,
  хотя аннотации DTO обещают `list<int|string>`.

**Как иначе:** проверять `null`/не-массив явно (не `empty`), фильтровать по реальному критерию
(скалярные не-пустые id) и переиндексировать через `array_values`.

### 3.9. Bulk DQL DELETE обходит ORM-каскады и события

`deleteByIds()` (`Service/DeletionOrchestrator.php:180`) выполняет `DELETE ... WHERE e.field IN (:ids)`
через DQL.

**Почему плохо:** bulk DQL DELETE не поднимает lifecycle-события Doctrine (`preRemove`/`postRemove`),
игнорирует `orphanRemoval` и каскады на уровне ORM — поведение тихо расходится с обычным `remove()`.
Если на сущностях завязаны доменные события — они не сработают.

**Как иначе:** осознанно выбрать стратегию и задокументировать её; при необходимости событий — грузить и
`remove()` (ценой производительности) либо явно чистить кэш через `$em->clear()`/detach после bulk-delete.

### 3.10. Ветка `hasHardParent` в `analyze()` фактически мертва

`analyze()` (`DeletionService.php:47`) вычисляет `hasHardParent`, перебирая `$parents` и проверяя
`$group->hard`. Но `findParentsByAttributes()` **всегда** проставляет `isBlocking = false`
(`DeletionService.php:122`, что концептуально верно: BLOCKING на ребёнке блокирует *родителя*, а не
саму дочернюю сущность). Значит все группы в `$parents` имеют `hard = false`, и условие
`if ($group->hard && $group->count > 0)` не срабатывает никогда.

**Почему важно:** это не баг поведения (блокировка и не должна идти от родителя), но целый блок кода
и поле `hard` в «родительских» `DependentGroupDto` — мёртвые/вводящие в заблуждение. Стоит убрать
проверку `hasHardParent` либо явно задокументировать, что родительские группы — всегда информационные.

### 3.11. Прочие мелочи

- `deleteByIds()` использует поле `field`, которое в `buildRecursive` (строки 112–116) выбирается как
  `'id'` или `group->field` эвристически — хрупко при составных/нестандартных идентификаторах.
- `notify('beforeDetachRelations', ...)` передаёт `$rel['childIds']` и весь `$rel` (в нём снова `childIds`) —
  дублирование данных в сигнатуре хука.

---

## 4. Производительность

### 4.1. N+1 в рекурсивном планировании (главное)

`buildRecursive()` (`Service/DeletionOrchestrator.php:124`) для каждого id ребёнка делает отдельный
`repository->find($cid)` (или `findOneBy`), затем `analyze()` для каждого по отдельности. На дереве из
тысяч записей это тысячи запросов.

**Как иначе:** батч-загрузка детей одного класса одним запросом (`findBy(['id' => $ids])`) и
пакетный анализ; кэшировать карту связей между узлами одного класса.

### 4.2. `ensureMap()` прогревает метаданные ВСЕХ сущностей

`ensureMap()` (`DeletionService.php:82`) вызывает `$this->em->getMetadataFactory()->getAllMetadata()`.

**Почему плохо:** на первом же обращении инициализируются метаданные всех сущностей приложения — дорого
в больших проектах и на «холодном» контейнере.

**Как иначе:** кэшировать готовую карту связей (PSR-6/PSR-16, или compile-time сборка в DI), а не строить
её из полного набора метаданных на каждый воркер-процесс.

### 4.3. Дублирование кэша метаданных

`DeletionService::$metadataCache` (`DeletionService.php:19`, `getEntityMetadata()`) дублирует внутренний
кэш `ClassMetadataFactory` Doctrine — лишняя сущность без выигрыша.

### 4.4. Хуки — внутри транзакции

Все `notify()` вызываются внутри `wrapInTransaction`. Медленный middleware (сетевой вызов в метрики,
удалённое логирование) удлиняет удержание блокировок БД.

**Как иначе:** для тяжёлых сайд-эффектов — буферизовать и отправлять после коммита (например, по
`postFlush`/после закрытия транзакции).

---

## 5. SOLID / PSR / Symfony best practices

- **SRP (`DeletionService`).** Класс совмещает: скан атрибутов, построение карты, работу с метаданными,
  парсинг JSON, запросы к join-таблицам, чтение FK через Reflection. Напрашивается декомпозиция:
  `RelationMapBuilder` (карта из атрибутов), `RelationResolver` (значения связей), `RelationAnalyzer`
  (правила блокировки).
- **OCP / типобезопасность.** Правила связей передаются позиционным массивом
  `array{0:string,1:string,2:bool,...}` (`DeletionService.php:17`, деструктуризация в `buildRecursive:94`).
  Легко перепутать порядок; напрашивается value-object `RelationRule` с именованными свойствами.
- **LSP / ISP (middleware).** `supports()` в контракте, но не соблюдается (см. 3.6). Либо соблюдать, либо
  убрать; сейчас реализация интерфейса вводит в заблуждение.
- **DIP.** Прямая зависимость от конкретного `GenericReadRepository` (`DeletionService.php:23`) вместо
  узкого интерфейса «читалки» — усложняет подмену и тестирование.
- **Reflection на горячем пути.** `new ReflectionClass()` создаётся многократно (в `findParentsByAttributes`,
  `getJsonArrayParentIds`, `getScalarFkValue` — по объекту и на каждый вызов). Стоит кэшировать по классу.
- **`ReflectionProperty::setAccessible(true)`** (`DeletionService.php:180`, `:235`) — начиная с PHP 8.1
  no-op, а с **PHP 8.5 помечен `@deprecated`** и шумит в логах (подтверждено прогоном тестов на 8.5).
  Вызовы можно просто удалить.
- **Консистентность DTO.** `RelationsDto` и `OrderedPlanDto` — `final class` с `readonly`-полями, тогда как
  `CanDeleteDto` / `DependentGroupDto` — `final readonly class`. Плюс докблок `OrderedPlanDto`
  (`Dto/OrderedPlanDto.php:10`) малформед: два `@param` в одной строке. Мелочь, но бьёт по единообразию.
- **PSR-3.** Логирование в `LoggingDeletionMiddleware` корректно использует `LoggerInterface` — хорошо;
  стоит распространить тот же подход (инъекция логгера) на потерю ошибок в `notify()`.
- **Именование.** README документирует атрибут `DependsOn` с параметрами `parent`/`hard`, а в коде —
  `RelationTo` с `entity`/`type`/`cascade` (см. 3 и ниже). Документация вводит в заблуждение — пример из
  README не скомпилируется. Требуется синхронизация README ↔ код.

---

## 6. Что бы я сделал иначе (и почему)

1. **Ввести `RelationRule` (value-object)** вместо позиционных массивов — устраняет класс ошибок
   «перепутан индекс» и самодокументирует правило.
2. **Планировщик с visited-set и топологическим порядком** — устраняет бесконечную рекурсию (3.2) и
   FK-ошибки при удалении (3.7).
3. **Сверять `canDelete` в `execute()`** (или явный `force`) — восстанавливает контракт блокировки (3.1).
4. **Батч-загрузка детей** одним запросом на класс — убирает N+1 (4.1).
5. **Native SQL для join-таблиц** в анализе — как уже сделано в `detachJoinRow` (3.3).
6. **Явная политика ошибок middleware** — глушить «наблюдателей», но логировать сам факт; критичные —
   прокидывать (3.5).
7. **Различать ассоциацию и скаляр** по метаданным Doctrine при чтении FK (3.4).
8. **Кэшировать карту связей** (PSR-6) вместо `getAllMetadata()` на каждый процесс (4.2).
9. **Синхронизировать README с кодом** — иначе онбординг по документации приводит к нерабочему примеру.

---

## 7. Сводная таблица находок

| # | Уровень | Файл:строка | Проблема |
|---|---------|-------------|----------|
| 3.1 | 🔴 критично | `Service/DeletionOrchestrator.php:28` | `execute()` не проверяет `canDelete` — BLOCKING не блокирует |
| 3.2 | 🔴 критично | `Service/DeletionOrchestrator.php:89` | Нет visited-set — бесконечная рекурсия на циклах |
| 3.3 | 🟠 высокий | `DeletionService.php:211` | DQL `from($joinTable)` по имени таблицы падает на M2M |
| 3.4 | 🟠 высокий | `DeletionService.php:227` | FK-ассоциация читается как скаляр — объект в `ids` |
| 3.5 | 🟠 высокий | `Service/DeletionOrchestrator.php:156` | `notify()` молча глотает исключения middleware |
| 3.7 | 🟠 высокий | `Service/DeletionOrchestrator.php:67` | Нетопологический порядок удаления — риск FK-ошибок |
| 4.1 | 🟠 высокий | `Service/DeletionOrchestrator.php:124` | N+1: `find()` в цикле + анализ каждого ребёнка |
| 3.6 | 🟡 средний | `Middleware/DeletionMiddlewareInterface.php:9` | `supports()` объявлен, но не вызывается (ISP) |
| 3.8 | 🟡 средний | `DeletionService.php:173` | JSON: теряется id `0`, слабый фильтр, не `list` |
| 3.9 | 🟡 средний | `Service/DeletionOrchestrator.php:180` | Bulk DQL DELETE обходит события/каскады ORM |
| 4.2 | 🟡 средний | `DeletionService.php:82` | `getAllMetadata()` прогревает все сущности |
| 5.x | 🟢 низкий | несколько | SRP/типизация правил, консистентность DTO, README↔код |

Легенда: 🔴 критично · 🟠 высокий · 🟡 средний · 🟢 низкий.
