<?php

declare(strict_types=1);

namespace Shared\Deletion\Service;

use Doctrine\ORM\EntityManagerInterface;
use Shared\Deletion\DeletionService;
use Shared\Deletion\Dto\{DependentGroupDto, OrderedPlanDto, RelationsDto};
use Shared\Deletion\Middleware\DeletionMiddlewareInterface;
use Throwable;

final class DeletionOrchestrator
{
    /**
     * @param iterable<DeletionMiddlewareInterface> $middlewares
     * @param EntityManagerInterface                $em
     * @param DeletionService                       $analyzer
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeletionService $analyzer,
        private readonly iterable $middlewares = []
    )
    {
    }

    public function execute(object $root, bool $dryRun = false): void
    {
        $relations = $this->plan($root);
        $plan = $this->buildOrderedPlan($root, $relations);

        $this->em->wrapInTransaction(function () use ($plan, $root, $dryRun): void {
            // 1) Detach
            foreach ($plan->detach as $rel) {
                $this->notify('beforeDetachRelations', $root::class, $rel['childClass'], $rel['childIds'], $rel, $root);
                if (!$dryRun) {
                    $this->detachJoinRow($rel['joinTable'], $rel['joinColumn'], $rel['inverseJoinColumn'], $rel['parentId'], $rel['childIds']);
                }
                $this->notify('afterDetachRelations', $root::class, $rel['childClass'], $rel['childIds'], $rel, $root);
            }

            // 2) Delete children
            foreach ($plan->delete as $del) {
                $this->notify('beforeDeleteChildren', $del['class'], $del['ids'], $root);
                if (!$dryRun) {
                    $this->deleteByIds($del['class'], $del['ids'], $del['field']);
                }
                $this->notify('afterDeleteChildren', $del['class'], $del['ids'], $root);
            }

            // 3) Delete root
            $this->notify('beforeDeleteRoot', $root);
            if (!$dryRun) {
                $this->em->remove($root);
                $this->em->flush();
            }
            $this->notify('afterDeleteRoot', $root);
        });
    }

    public function plan(object $root): RelationsDto
    {
        return $this->analyzer->analyze($root);
    }

    private function buildOrderedPlan(object $root, RelationsDto $relations): OrderedPlanDto
    {
        // Рекурсивный сбор плана
        $deleteMap = []; // class => set(ids)
        $detach = [];
        $this->buildRecursive($root, $relations, $deleteMap, $detach);

        // Преобразуем deleteMap в массив
        $delete = [];
        foreach ($deleteMap as $class => $idsSet) {
            $delete[] = ['class' => $class, 'ids' => array_values($idsSet['ids']), 'field' => $idsSet['field']];
        }

        return new OrderedPlanDto($delete, $detach);
    }

    /**
     * @param array<string,array<int|string,int|string>>                                                                                         $deleteMap
     * @param array<int,array{joinTable:string,joinColumn:string,inverseJoinColumn:string,parentId:int|string,childClass:string,childIds:array}> $detach
     * @param object                                                                                                                             $parent
     * @param RelationsDto                                                                                                                       $relations
     */
    private function buildRecursive(object $parent, RelationsDto $relations, array &$deleteMap, array &$detach): void
    {
        // 1) Detach текущего уровня (по карте правил)
        $parentIdArr = $this->em->getClassMetadata($parent::class)->getIdentifierValues($parent);
        $parentId = array_values($parentIdArr)[0] ?? null;
        foreach ($this->analyzer->getChildRelationRules($parent::class) as [$childClass, $field, $isBlocking, $joinTable, $joinColumn, $inverseJoinColumn, $cascade]) {
            if ($joinTable && $cascade === 'detach') {
                $group = $this->findGroup($relations->childrenDetach, $childClass);
                if ($group && $group->ids !== []) {
                    $detach[] = [
                        'joinTable' => $joinTable,
                        'joinColumn' => $joinColumn,
                        'inverseJoinColumn' => $inverseJoinColumn,
                        'parentId' => $parentId,
                        'childClass' => $childClass,
                        'childIds' => $group->ids,
                    ];
                }
            }
        }

        // 2) Для каждого ребенка с каскадным удалением: добавить в deleteMap и рекурсивно спускаться
        foreach ($relations->childrenDelete as $group) {
            $identifiers = $this->em->getClassMetadata($group->childClass)->getIdentifier();
            $idField = 'id';
            if (!in_array('id', $identifiers)) {
                $idField = $group->field;
            }
            // добавить ids в deleteMap
            $deleteMap[$group->childClass] = $deleteMap[$group->childClass] ?? ['ids' => [], 'field' => $idField];
            foreach ($group->ids as $cid) {
                $deleteMap[$group->childClass]['ids'][(string) $cid] = $cid;
            }

            // рекурсия для каждого id
            foreach ($group->ids as $cid) {
                $repository = $this->em->getRepository($group->childClass);
                $child = null;
                if (in_array('id', $identifiers)) {
                    $child = $repository->find($cid);
                }
                if (!$child && $group->field) {
                    $child = $repository->findOneBy([$group->field => $cid]);
                }

                if (!$child) {
                    continue;
                }
                $childRelations = $this->analyzer->analyze($child);
                $this->buildRecursive($child, $childRelations, $deleteMap, $detach);
            }
        }
    }

    /**
     * @param list<DependentGroupDto> $groups
     * @param string                  $childClass
     */
    private function findGroup(array $groups, string $childClass): ?DependentGroupDto
    {
        foreach ($groups as $g) {
            if ($g->childClass === $childClass) return $g;
        }

        return null;
    }

    private function notify(string $method, mixed ...$args): void
    {
        foreach ($this->middlewares as $mw) {
            if (method_exists($mw, $method)) {
                try {
                    $mw->{$method}(...$args);
                } catch (Throwable) {
                }
            }
        }
    }

    private function detachJoinRow(string $joinTable, string $joinColumn, string $inverseJoinColumn, int|string $parentId, array $childIds): void
    {
        $conn = $this->em->getConnection();
        $inPlaceholders = implode(',', array_fill(0, count($childIds), '?'));
        $sql = sprintf('DELETE FROM %s WHERE %s = ? AND %s IN (%s)', $joinTable, $joinColumn, $inverseJoinColumn, $inPlaceholders);
        $conn->executeStatement($sql, array_merge([$parentId], $childIds));
    }

    /**
     * @param array<int|string> $ids
     * @param string            $entityClass
     */
    private function deleteByIds(string $entityClass, array $ids, string $field): void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->delete($entityClass, 'e')
            ->where($qb->expr()->in(sprintf('e.%s', $field), ':ids'))
            ->setParameter('ids', $ids)
            ->getQuery()->execute()
        ;
    }

    public function getOrderedPlan(object $root): OrderedPlanDto
    {
        $relations = $this->plan($root);

        return $this->buildOrderedPlan($root, $relations);
    }
}
