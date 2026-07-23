<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\DeletionCascade;
use Shared\Deletion\Enum\RelationType;

/*
 * Лёгкие фикстуры-сущности для DeletionServiceTest. Атрибуты RelationTo читаются НАСТОЯЩИМ
 * Reflection'ом (метаданные Doctrine мокаются отдельно). Каждый «родитель» уникален, чтобы
 * карта связей map[parentClass] содержала ровно одно правило и сценарии были изолированы.
 *
 * Файл подключается через composer autoload-dev "files" — все классы гарантированно загружены.
 */

// --- Родители (без атрибутов, свободно удаляемы) ---

final class ScalarParentFixture
{
    public int $id = 100;
}

final class DetachParentFixture
{
    public int $id = 200;
}

final class NoneParentFixture
{
    public int $id = 300;
}

final class JsonRefParentFixture
{
    public int $id = 350;
}

/** Ни на кого не ссылается и никем не используется — полностью изолирован. */
final class IsolatedFixture
{
    public int $id = 400;
}

final class MultiParentAFixture
{
    public int $id = 510;
}

final class MultiParentBFixture
{
    public int $id = 520;
}

// --- Дети (с атрибутами связей) ---

/** Прямая ссылка + каскадное удаление ребёнка. BLOCKING ⇒ блокирует удаление родителя. */
#[RelationTo(
    entity: ScalarParentFixture::class,
    field: 'parentId',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DELETE_CHILD
)]
final class ChildScalarFixture
{
    public int $id = 1;
    public int $parentId = 100;
}

/** Many-to-many через промежуточную таблицу + отвязка. DETACH ⇒ НЕ блокирует родителя. */
#[RelationTo(
    entity: DetachParentFixture::class,
    field: 'id',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DETACH_RELATIONS,
    joinTable: 'detach_rel',
    joinColumn: 'parent_id',
    inverseJoinColumn: 'child_id'
)]
final class DetachChildFixture
{
    public int $id = 2;
}

/** BLOCKING + cascade NONE ⇒ попадает в childrenDelete (ветка elseif ($hard)), блокирует родителя. */
#[RelationTo(
    entity: NoneParentFixture::class,
    field: 'parentId',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::NONE
)]
final class NoneBlockingChildFixture
{
    public int $id = 3;
    public int $parentId = 300;
}

/** Ссылка на родителей через JSON-массив id. Поле намеренно нетипизировано (может быть array или JSON-строка). */
#[RelationTo(
    entity: JsonRefParentFixture::class,
    field: 'parentIds',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::NONE
)]
final class JsonParentRefFixture
{
    public int $id = 4;

    /** @var array<int|string>|string|null */
    public $parentIds = [10, 20];
}

// --- Родители для тестов дополнительных веток ---

final class M2MParentFixture
{
    public int $id = 800;
}

final class AssocParentFixture
{
    public int $id = 900;
}

final class UninitParentFixture
{
    public int $id = 1000;
}

final class JsonChildParentFixture
{
    public int $id = 1100;
}

/** M2M-связь на стороне РОДИТЕЛЯ: id родителей берутся из join-таблицы через DBAL. */
#[RelationTo(
    entity: M2MParentFixture::class,
    field: 'id',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::NONE,
    joinTable: 'm2m_rel',
    joinColumn: 'parent_col',
    inverseJoinColumn: 'child_col'
)]
final class ManyToManyChildFixture
{
    public int $id = 7;
}

/** FK как объект-ассоциация: id должен извлекаться из связанной сущности, а не попадать объектом. */
#[RelationTo(
    entity: AssocParentFixture::class,
    field: 'parent',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::NONE
)]
final class AssociationChildFixture
{
    public int $id = 950;
    public ?AssocParentFixture $parent = null;
}

/** Неинициализированное typed-свойство FK: чтение не должно бросать Error. */
#[RelationTo(
    entity: UninitParentFixture::class,
    field: 'parentId',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::NONE
)]
final class UninitializedFkFixture
{
    public int $id = 60;
    public int $parentId; // без значения по умолчанию — не инициализировано
}

/** Дочерняя связь через JSON-поле (child-side, findByJsonContains). */
#[RelationTo(
    entity: JsonChildParentFixture::class,
    field: 'parentRef',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DELETE_CHILD
)]
final class JsonChildFixture
{
    public int $id = 8;

    /** @var array<int|string>|string */
    public $parentRef = [];
}

/** Несколько RelationTo на одном классе (две независимые родительские связи). */
#[RelationTo(
    entity: MultiParentAFixture::class,
    field: 'parentAId',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::NONE
)]
#[RelationTo(
    entity: MultiParentBFixture::class,
    field: 'parentBId',
    type: RelationType::REFERENCE,
    cascade: DeletionCascade::NONE
)]
final class MultiParentChildFixture
{
    public int $id = 5;
    public int $parentAId = 510;
    public int $parentBId = 520;
}
