<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\TestCase;
use Shared\Deletion\DeletionService;
use Shared\Deletion\Tests\Fixture\ChildScalarFixture;
use Shared\Deletion\Tests\Fixture\DetachChildFixture;
use Shared\Deletion\Tests\Fixture\DetachParentFixture;
use Shared\Deletion\Tests\Fixture\IsolatedFixture;
use Shared\Deletion\Tests\Fixture\JsonParentRefFixture;
use Shared\Deletion\Tests\Fixture\MultiParentChildFixture;
use Shared\Deletion\Tests\Fixture\NoneBlockingChildFixture;
use Shared\Deletion\Tests\Fixture\NoneParentFixture;
use Shared\Deletion\Tests\Fixture\ScalarParentFixture;
use Shared\Persistence\GenericReadRepository;

/**
 * Unit-тесты аналитической части модуля (DeletionService::analyze / canDelete).
 *
 * Doctrine EntityManager и GenericReadRepository мокаются; атрибуты RelationTo на фикстурах
 * читаются настоящим Reflection'ом. Часть тестов намеренно фиксирует ТЕКУЩЕЕ поведение (в т.ч.
 * спорные места, разобранные в REVIEW.md) — при исправлении бага такой тест подскажет, что контракт
 * поменялся.
 */
final class DeletionServiceTest extends TestCase
{
    /** Классы с атрибутами RelationTo, из которых строится карта связей (ensureMap). */
    private const ATTRIBUTED = [
        ChildScalarFixture::class,
        DetachChildFixture::class,
        NoneBlockingChildFixture::class,
        JsonParentRefFixture::class,
        MultiParentChildFixture::class,
    ];

    /** Классы, у которых поле трактуется Doctrine как json. */
    private const JSON_FIELDS = [
        JsonParentRefFixture::class => ['parentIds' => ['type' => 'json']],
    ];

    public function testEntityWithoutRelationsCanBeDeleted(): void
    {
        $svc = $this->service($this->finder());

        $dto = $svc->canDelete(new IsolatedFixture());

        self::assertTrue($dto->canDelete);
        self::assertSame([], $dto->dependents);
    }

    public function testHardDeleteChildrenBlockParentDeletion(): void
    {
        $finder = $this->finder(byAssociation: [new ChildScalarFixture()]);

        $analysis = $this->service($finder)->analyze(new ScalarParentFixture());

        self::assertFalse($analysis->canDelete, 'BLOCKING + DELETE_CHILD должен блокировать удаление родителя');
        self::assertCount(1, $analysis->childrenDelete);
        self::assertSame(ChildScalarFixture::class, $analysis->childrenDelete[0]->childClass);
        self::assertTrue($analysis->childrenDelete[0]->hard);
    }

    public function testDetachRelationsDoNotBlockParentDeletion(): void
    {
        $finder = $this->finder(byJoinTable: [new DetachChildFixture()]);

        $analysis = $this->service($finder)->analyze(new DetachParentFixture());

        self::assertTrue($analysis->canDelete, 'DETACH_RELATIONS не должен блокировать родителя, даже при BLOCKING');
        self::assertCount(1, $analysis->childrenDetach);
        self::assertCount(0, $analysis->childrenDelete);
    }

    public function testBlockingChildrenWithCascadeNoneStillBlockParent(): void
    {
        $finder = $this->finder(byAssociation: [new NoneBlockingChildFixture()]);

        $analysis = $this->service($finder)->analyze(new NoneParentFixture());

        // cascade=NONE, но type=BLOCKING ⇒ ветка elseif ($hard) кладёт связь в childrenDelete
        self::assertFalse($analysis->canDelete);
        self::assertCount(1, $analysis->childrenDelete);
    }

    public function testParentReferenceNeverBlocksTheChildItself(): void
    {
        // ChildScalarFixture ссылается на BLOCKING-родителя, но сам ребёнок удаляем свободно:
        // BLOCKING на ребёнке блокирует РОДИТЕЛЯ, а не дочернюю сущность (DeletionService:122).
        $analysis = $this->service($this->finder())->analyze(new ChildScalarFixture());

        self::assertTrue($analysis->canDelete);
        self::assertCount(1, $analysis->parents);
        self::assertFalse($analysis->parents[0]->hard, 'родительская связь всегда информационная (hard=false)');
        self::assertSame([100], array_values($analysis->parents[0]->ids));
    }

    public function testScalarForeignKeyEqualToZeroIsDropped(): void
    {
        // Фиксируем известный edge case (REVIEW.md 3.8): валидный FK = 0 отбрасывается.
        $child = new ChildScalarFixture();
        $child->parentId = 0;

        $analysis = $this->service($this->finder())->analyze($child);

        self::assertCount(0, $analysis->parents);
    }

    public function testJsonArrayParentIdsAreCollected(): void
    {
        $analysis = $this->service($this->finder())->analyze(new JsonParentRefFixture());

        self::assertCount(1, $analysis->parents);
        self::assertSame([10, 20], array_values($analysis->parents[0]->ids));
        self::assertSame(2, $analysis->parents[0]->count);
    }

    public function testJsonEncodedStringIsDecoded(): void
    {
        $fixture = new JsonParentRefFixture();
        $fixture->parentIds = '[30,40]';

        $analysis = $this->service($this->finder())->analyze($fixture);

        self::assertSame([30, 40], array_values($analysis->parents[0]->ids));
    }

    public function testInvalidJsonYieldsNoParents(): void
    {
        $fixture = new JsonParentRefFixture();
        $fixture->parentIds = '{broken';

        $analysis = $this->service($this->finder())->analyze($fixture);

        self::assertCount(0, $analysis->parents);
    }

    public function testMultipleRelationToAttributesAreAllResolved(): void
    {
        $analysis = $this->service($this->finder())->analyze(new MultiParentChildFixture());

        self::assertCount(2, $analysis->parents);
    }

    public function testCanDeleteMergesAllDependentGroups(): void
    {
        $finder = $this->finder(byAssociation: [new ChildScalarFixture()]);

        $dto = $this->service($finder)->canDelete(new ScalarParentFixture());

        self::assertFalse($dto->canDelete);
        self::assertCount(1, $dto->dependents, 'dependents = parents + childrenDelete + childrenDetach');
    }

    public function testGetChildRelationRulesReturnsRulesFromMap(): void
    {
        $rules = $this->service($this->finder())->getChildRelationRules(ScalarParentFixture::class);

        self::assertCount(1, $rules);
        self::assertSame(ChildScalarFixture::class, $rules[0][0]);
        self::assertSame('parentId', $rules[0][1]);
        self::assertTrue($rules[0][2]);            // isBlocking
        self::assertSame('delete', $rules[0][6]);  // cascade
    }

    // ---- helpers ----

    private function service(GenericReadRepository $finder): DeletionService
    {
        return new DeletionService($this->entityManager(), $finder);
    }

    /**
     * @param list<object> $byAssociation результат findByAssociation (прямая ссылка)
     * @param list<object> $byJoinTable   результат findByJoinTable (many-to-many)
     * @param list<object> $byJson        результат findByJsonContains (JSON-поле)
     *
     * @return GenericReadRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function finder(array $byAssociation = [], array $byJoinTable = [], array $byJson = []): GenericReadRepository
    {
        $finder = $this->createMock(GenericReadRepository::class);
        // id извлекаем из публичного поля фикстуры — удобно для parent/child объектов.
        $finder->method('getId')->willReturnCallback(static fn (object $o): int => $o->id);
        $finder->method('findByAssociation')->willReturn($byAssociation);
        $finder->method('findByJoinTable')->willReturn($byJoinTable);
        $finder->method('findByJsonContains')->willReturn($byJson);

        return $finder;
    }

    private function entityManager(): EntityManagerInterface
    {
        $factory = $this->createMock(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn(
            array_map(fn (string $class): ClassMetadata => $this->metadataFor($class), self::ATTRIBUTED)
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($factory);
        $em->method('getClassMetadata')->willReturnCallback(fn (string $class): ClassMetadata => $this->metadataFor($class));

        return $em;
    }

    private function metadataFor(string $class): ClassMetadata
    {
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getName')->willReturn($class);
        $meta->fieldMappings = self::JSON_FIELDS[$class] ?? [];

        return $meta;
    }
}
