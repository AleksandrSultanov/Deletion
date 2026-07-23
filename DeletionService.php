<?php

declare(strict_types=1);

namespace Shared\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Dto\{CanDeleteDto, DependentGroupDto, RelationRule, RelationsDto};
use Shared\Deletion\Enum\{DeletionCascade, RelationType};
use Shared\Persistence\GenericReadRepository;

final class DeletionService
{
    /** @var array<string, list<RelationRule>>|null parentFqcn => правила связей */
    private ?array $map = null;

    /** @var array<string, ClassMetadata> */
    private array $metadataCache = [];

    /** @var array<string, ReflectionClass> */
    private array $reflectionCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenericReadRepository $finder
    ) {
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

        // Блокируют удаление только ЖЁСТКИЕ childrenDelete. Родительские связи всегда информационные
        // (isBlocking=false, см. CLAUDE.md: BLOCKING на ребёнке блокирует РОДИТЕЛЯ, а не сам объект) —
        // поэтому отдельной проверки по $parents здесь нет (была мёртвой, F12).
        $hasHardChildren = false;
        foreach ($childrenDelete as $group) {
            if ($group->hard && $group->count > 0) {
                $hasHardChildren = true;
                break;
            }
        }

        return new RelationsDto(
            parents: $parents,
            childrenDelete: $childrenDelete,
            childrenDetach: $childrenDetach,
            canDelete: !$hasHardChildren,
        );
    }

    private function ensureMap(): void
    {
        if ($this->map !== null) {
            return;
        }

        $map = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $fqcn = $meta->getName();

            foreach ($this->reflection($fqcn)->getAttributes(RelationTo::class) as $attributeRef) {
                /** @var RelationTo $attribute */
                $attribute = $attributeRef->newInstance();

                $map[$attribute->entity][] = new RelationRule(
                    childClass: $fqcn,
                    field: $attribute->field,
                    isBlocking: $attribute->type === RelationType::BLOCKING,
                    cascade: $attribute->cascade,
                    joinTable: $attribute->joinTable,
                    joinColumn: $attribute->joinColumn,
                    inverseJoinColumn: $attribute->inverseJoinColumn,
                );
            }
        }

        $this->map = $map;
    }

    /**
     * Ищет зависимости текущей сущности от родительских (объект как «ребёнок»).
     *
     * @return list<DependentGroupDto>
     */
    private function findParentsByAttributes(object $object): array
    {
        $entityClass = $object::class;
        $dependencies = [];

        foreach ($this->reflection($entityClass)->getAttributes(RelationTo::class) as $attributeRef) {
            /** @var RelationTo $attribute */
            $attribute = $attributeRef->newInstance();

            // Родительская связь никогда не блокирует сам объект ⇒ hard=false.
            if ($this->isJsonField($entityClass, $attribute->field)) {
                $parentIds = $this->getJsonArrayParentIds($object, $attribute->field);
                if ($parentIds !== []) {
                    $dependencies[] = DependentGroupDto::of($attribute->entity, false, $parentIds, $attribute->field);
                }
            } elseif ($this->isJoinTableRelation($attribute)) {
                $parentIds = $this->getJoinTableParentIds(
                    $object,
                    $attribute->joinTable,
                    $attribute->joinColumn,
                    $attribute->inverseJoinColumn
                );
                if ($parentIds !== []) {
                    $dependencies[] = DependentGroupDto::of($attribute->entity, false, $parentIds, $attribute->field);
                }
            } else {
                $parentId = $this->getForeignKeyValue($object, $attribute->field);
                if ($parentId !== null && $parentId !== 0 && $parentId !== '') {
                    $dependencies[] = DependentGroupDto::of($attribute->entity, false, [$parentId], $attribute->field);
                }
            }
        }

        return $dependencies;
    }

    /**
     * ID родителей из JSON-массива. Сохраняет валидные числовые id (в т.ч. 0), отбрасывает пустые строки,
     * переиндексирует результат в list (F13).
     *
     * @return list<int|string>
     */
    private function getJsonArrayParentIds(object $object, string $jsonField): array
    {
        $value = $this->readProperty($object, $jsonField);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = array_filter(
            $value,
            static fn ($id): bool => is_int($id) || (is_string($id) && $id !== '')
        );

        return array_values($ids);
    }

    /**
     * ID родителей, связанных через промежуточную таблицу. Native SQL через DBAL — join-таблица не
     * является Doctrine-сущностью, поэтому DQL здесь неприменим (F3). Идентификаторы квотируются (F22).
     *
     * @return list<int|string>
     */
    private function getJoinTableParentIds(object $object, string $joinTable, string $joinColumn, string $inverseJoinColumn): array
    {
        $objectId = $this->finder->getId($object);

        $conn = $this->em->getConnection();
        $platform = $conn->getDatabasePlatform();

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $platform->quoteIdentifier($joinColumn),
            $platform->quoteIdentifier($joinTable),
            $platform->quoteIdentifier($inverseJoinColumn)
        );

        /** @var list<int|string> $result */
        $result = $conn->fetchFirstColumn($sql, [$objectId]);

        return array_values($result);
    }

    /**
     * Значение внешнего ключа. Различает скалярный FK и объект-ассоциацию: для ассоциации возвращает
     * идентификатор связанной сущности, а не сам объект/proxy (F4). Неинициализированные typed-свойства
     * не приводят к Error (F5).
     */
    private function getForeignKeyValue(object $object, string $field): int|string|null
    {
        $value = $this->readProperty($object, $field);

        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            return $this->extractId($value);
        }

        return is_int($value) || is_string($value) ? $value : null;
    }

    private function extractId(object $entity): int|string|null
    {
        $ids = $this->getEntityMetadata($entity::class)->getIdentifierValues($entity);
        $first = array_values($ids)[0] ?? null;

        return is_int($first) || is_string($first) ? $first : null;
    }

    /**
     * Безопасно читает значение свойства через Reflection: отсутствующее или неинициализированное
     * typed-свойство даёт null вместо исключения (F5). setAccessible не нужен на PHP 8.1+ (F18).
     */
    private function readProperty(object $object, string $field): mixed
    {
        $reflection = $this->reflection($object::class);
        if (!$reflection->hasProperty($field)) {
            return null;
        }

        $property = $reflection->getProperty($field);
        if (!$property->isInitialized($object)) {
            return null;
        }

        return $property->getValue($object);
    }

    /**
     * Ищет дочерние записи по карте (child-классы, зависящие от нас).
     *
     * @return array{0: list<DependentGroupDto>, 1: list<DependentGroupDto>} [childrenDelete, childrenDetach]
     */
    private function findChildrenByAttributes(object $object): array
    {
        $childrenDelete = [];
        $childrenDetach = [];
        $parentClass = $object::class;

        foreach ($this->map[$parentClass] ?? [] as $rule) {
            // NONE + REFERENCE не влияет ни на блокировку, ни на каскад — не тратим запрос в БД (F17).
            if ($rule->cascade === DeletionCascade::NONE && !$rule->isBlocking) {
                continue;
            }

            $ids = $this->collectChildIds($object, $rule);
            if ($ids === []) {
                continue;
            }

            if ($rule->cascade === DeletionCascade::DELETE_CHILD) {
                $childrenDelete[] = DependentGroupDto::of($rule->childClass, $rule->isBlocking, $ids, $rule->field);
            } elseif ($rule->cascade === DeletionCascade::DETACH_RELATIONS) {
                $childrenDetach[] = DependentGroupDto::of($rule->childClass, $rule->isBlocking, $ids, $rule->field);
            } elseif ($rule->isBlocking) {
                // NONE + BLOCKING: не каскадим, но связь блокирует удаление родителя — учитываем.
                $childrenDelete[] = DependentGroupDto::of($rule->childClass, $rule->isBlocking, $ids, $rule->field);
            }
        }

        return [$childrenDelete, $childrenDetach];
    }

    /**
     * @return list<int|string>
     */
    private function collectChildIds(object $object, RelationRule $rule): array
    {
        if ($this->isJsonField($rule->childClass, $rule->field)) {
            $children = $this->finder->findByJsonContains(
                entityClass: $rule->childClass,
                field: $rule->field,
                value: $this->finder->getId($object)
            );
        } elseif ($rule->isJoinTable()) {
            $children = $this->finder->findByJoinTable(
                $rule->childClass,
                $rule->joinTable,
                $rule->joinColumn,
                $rule->inverseJoinColumn,
                $object
            );
        } else {
            $children = $this->finder->findByAssociation($rule->childClass, $rule->field, $object);
        }

        $ids = [];
        foreach ($children as $child) {
            $ids[] = $this->finder->getId($child);
        }

        return $ids;
    }

    /**
     * Правила дочерних связей для заданного родителя.
     *
     * @return list<RelationRule>
     */
    public function getChildRelationRules(string $parentClass): array
    {
        $this->ensureMap();

        return $this->map[$parentClass] ?? [];
    }

    private function isJoinTableRelation(RelationTo $attribute): bool
    {
        return $attribute->joinTable !== null
            && $attribute->joinColumn !== null
            && $attribute->inverseJoinColumn !== null;
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
        return $this->metadataCache[$entityClass] ??= $this->em->getClassMetadata($entityClass);
    }

    private function reflection(string $class): ReflectionClass
    {
        return $this->reflectionCache[$class] ??= new ReflectionClass($class);
    }
}
