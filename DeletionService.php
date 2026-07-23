<?php

declare(strict_types=1);

namespace Shared\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Dto\{CanDeleteDto, DependentGroupDto, RelationsDto};
use Shared\Deletion\Enum\{DeletionCascade, RelationType};
use Shared\Persistence\GenericReadRepository;

final class DeletionService
{
    /** @var array<string, list<array{0:string,1:string,2:bool,3:?string,4:?string,5:?string,6:?string}>> parentFqcn => [[childFqcn, field, isBlocking, joinTable, joinColumn, inverseJoinColumn, cascade], ...] */
    private ?array $map = null;
    private array $metadataCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenericReadRepository $finder
    )
    {
    }

    public function canDelete(object $object): CanDeleteDto
    {
        $relations = $this->analyze($object);

        return new CanDeleteDto(
            $relations->canDelete,
            array_merge($relations->parents, $relations->childrenDelete, $relations->childrenDetach)
        );
    }

    public function analyze(object $object): RelationsDto
    {
        $this->ensureMap();

        $parents = $this->findParentsByAttributes($object);
        [$childrenDelete, $childrenDetach] = $this->findChildrenByAttributes($object);

        // Блокируем если есть ЖЁСТКИЕ зависимости от родителей
        $hasHardParent = false;
        foreach ($parents as $group) {
            if ($group->hard && $group->count > 0) {
                $hasHardParent = true;
                break;
            }
        }

        // Блокируем если есть ЖЁСТКИЕ дочерние зависимости (BLOCKING children)
        // Только для DELETE_CHILD, потому что DETACH_RELATIONS не должен блокировать родителя
        $hasHardChildren = false;
        foreach ($childrenDelete as $group) {
            if ($group->hard && $group->count > 0) {
                $hasHardChildren = true;
                break;
            }
        }
        // childrenDetach (DETACH_RELATIONS) НЕ блокирует удаление родителя,
        // даже если связь помечена как BLOCKING (BLOCKING блокирует удаление дочерней сущности, а не родителя)

        return new RelationsDto(
            parents: $parents,
            childrenDelete: $childrenDelete,
            childrenDetach: $childrenDetach,
            canDelete: !$hasHardParent && !$hasHardChildren,
        );
    }

    private function ensureMap(): void
    {
        if ($this->map !== null) {
            return;
        }

        $this->map = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $fqcn = $meta->getName();
            $rc = new ReflectionClass($fqcn);

            // Новый атрибут RelationTo
            foreach ($rc->getAttributes(RelationTo::class) as $attributeRef) {
                /** @var RelationTo $attribute */
                $attribute = $attributeRef->newInstance();
                $isBlocking = $attribute->type === RelationType::BLOCKING;
                $this->map[$attribute->entity][] = [
                    $fqcn,
                    $attribute->field,
                    $isBlocking,
                    $attribute->joinTable,
                    $attribute->joinColumn,
                    $attribute->inverseJoinColumn,
                    $attribute->cascade->value,
                ];
            }
        }
    }

    /**
     * Ищет зависимости текущей сущности от родительских сущностей.
     *
     * @param object $object
     *
     * @return DependentGroupDto[]
     */
    private function findParentsByAttributes(object $object): array
    {
        $entityClass = $object::class;
        $rc = new ReflectionClass($entityClass);
        $dependencies = [];

        foreach ($rc->getAttributes(RelationTo::class) as $attributeRef) {
            /** @var RelationTo $attribute */
            $attribute = $attributeRef->newInstance();
            // Родительские связи НИКОГДА не должны блокировать удаление текущей сущности
            // BLOCKING на дочерней сущности означает "нельзя удалить РОДИТЕЛЯ", а не саму дочернюю сущность
            $isBlocking = false;
            $isJsonField = $this->isJsonField($entityClass, $attribute->field);

            if ($isJsonField) {
                // Поиск по JSON массиву
                $parentIds = $this->getJsonArrayParentIds($object, $attribute->field);
                if (!empty($parentIds)) {
                    $dependencies[] = new DependentGroupDto(
                        $attribute->entity,
                        $isBlocking,
                        count($parentIds),
                        $parentIds,
                        $attribute->field
                    );
                }
            } elseif ($attribute->joinTable !== null && $attribute->joinColumn !== null && $attribute->inverseJoinColumn !== null) {
                $parentIds = $this->getJoinTableParentIds($object, $attribute->joinTable, $attribute->joinColumn, $attribute->inverseJoinColumn);
                if (!empty($parentIds)) {
                    $dependencies[] = new DependentGroupDto(
                        $attribute->entity,
                        $isBlocking,
                        count($parentIds),
                        $parentIds,
                        $attribute->field
                    );
                }
            } else {
                $parentId = $this->getScalarFkValue($object, $attribute->field);
                if ($parentId !== null && $parentId !== 0 && $parentId !== '') {
                    $dependencies[] = new DependentGroupDto(
                        $attribute->entity,
                        $isBlocking,
                        1,
                        [$parentId],
                        $attribute->field
                    );
                }
            }
        }

        return $dependencies;
    }

    /**
     * Получает ID родительских сущностей из JSON массива.
     *
     * @param object $object
     * @param string $jsonField
     *
     * @return array<int|string>
     */
    private function getJsonArrayParentIds(object $object, string $jsonField): array
    {
        $reflection = new ReflectionClass($object);
        if (!$reflection->hasProperty($jsonField)) {
            return [];
        }
        $property = $reflection->getProperty($jsonField);
        $property->setAccessible(true);
        $jsonValue = $property->getValue($object);

        if (empty($jsonValue)) {
            return [];
        }

        if (is_string($jsonValue)) {
            $jsonValue = json_decode($jsonValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
        }

        if (!is_array($jsonValue)) {
            return [];
        }

        return array_filter($jsonValue, static fn ($id) => is_numeric($id) || is_string($id));
    }

    /**
     * Получает ID всех родительских сущностей, связанных через промежуточную таблицу.
     *
     * @param object $object
     * @param string $joinTable
     * @param string $joinColumn
     * @param string $inverseJoinColumn
     *
     * @return array<int|string>
     */
    private function getJoinTableParentIds(object $object, string $joinTable, string $joinColumn, string $inverseJoinColumn): array
    {
        $objectId = $this->finder->getId($object);

        $qb = $this->em->createQueryBuilder();
        $qb->select("jt.{$joinColumn}")
            ->from($joinTable, 'jt')
            ->where("jt.{$inverseJoinColumn} = :objectId")
            ->setParameter('objectId', $objectId)
        ;

        $result = $qb->getQuery()->getArrayResult();

        return array_map(static fn ($row) => $row[$joinColumn], $result);
    }

    private function getScalarFkValue(object $object, string $field): int|string|null
    {
        $reflection = new ReflectionClass($object);
        if (!$reflection->hasProperty($field)) {
            return null;
        }

        $property = $reflection->getProperty($field);
        $property->setAccessible(true);

        // @var int|string|null $value
        return $property->getValue($object);
    }

    /**
     * Ищет дочерние записи на основе карты атрибутов (child-классы, зависящие от нас).
     *
     * @param object $object
     *
     * @return DependentGroupDto[]
     */
    private function findChildrenByAttributes(object $object): array
    {
        $childrenDelete = [];
        $childrenDetach = [];
        $parentClass = $object::class;
        $parentId = $this->finder->getId($object);
        foreach ($this->map[$parentClass] ?? [] as [$childClass, $field, $hard, $joinTable, $joinColumn, $inverseJoinColumn, $cascade]) {
            $ids = [];
            $isDelete = $cascade === DeletionCascade::DELETE_CHILD->value;
            $isDetach = $cascade === DeletionCascade::DETACH_RELATIONS->value;
            $isJsonField = $this->isJsonField($childClass, $field);

            if ($isJsonField) {
               $childEntities = $this->finder->findByJsonContains(
                   entityClass: $childClass,
                   field: $field,
                   value: $parentId
               );
                foreach ($childEntities as $child) {
                    $ids[] = $this->finder->getId($child);
                }
            } elseif ($joinTable !== null && $joinColumn !== null && $inverseJoinColumn !== null) {
                // Дети через join table: выберем детей и соберем их id
                $childEntities = $this->finder->findByJoinTable($childClass, $joinTable, $joinColumn, $inverseJoinColumn, $object);
                foreach ($childEntities as $child) {
                    $ids[] = $this->finder->getId($child);
                }
            } else {
                // Прямая ссылка: фильтрация по scalar FK у ребенка
                $children = $this->finder->findByAssociation($childClass, $field, $object);
                foreach ($children as $child) {
                    $ids[] = $this->finder->getId($child);
                }
            }

            if ($ids !== []) {
                if ($isDelete) {
                    $childrenDelete[] = new DependentGroupDto($childClass, $hard, count($ids), $ids, $field);
                } elseif ($isDetach) {
                    $childrenDetach[] = new DependentGroupDto($childClass, $hard, count($ids), $ids, $field);
                } elseif ($hard) {
                    // Для BLOCKING связей с cascade=NONE всё равно добавляем в childrenDelete,
                    // чтобы они учитывались при проверке возможности удаления
                    $childrenDelete[] = new DependentGroupDto($childClass, $hard, count($ids), $ids, $field);
                }
            }
        }

        return [$childrenDelete, $childrenDetach];
    }

    /**
     * Возвращает правила дочерних связей для заданного родителя из карты.
     *
     * @param string $parentClass
     *
     * @return list<array{0:string,1:string,2:bool,3:?string,4:?string,5:?string,6:?string}>
     */
    public function getChildRelationRules(string $parentClass): array
    {
        $this->ensureMap();

        return $this->map[$parentClass] ?? [];
    }

    private function isJsonField(string $entityClass, string $field): bool
    {
        $metadata = $this->getEntityMetadata($entityClass);

        if (!isset($metadata->fieldMappings[$field])) {
            return false;
        }

        $fieldMapping = $metadata->fieldMappings[$field];

        return $fieldMapping['type'] === 'json'
            || $fieldMapping['type'] === 'json_array'
            || (isset($fieldMapping['options']['json']) && $fieldMapping['options']['json']);
    }

    private function getEntityMetadata(string $entityClass): ClassMetadata
    {
        if (!isset($this->metadataCache[$entityClass])) {
            $this->metadataCache[$entityClass] = $this->em->getClassMetadata($entityClass);
        }

        return $this->metadataCache[$entityClass];
    }
}
