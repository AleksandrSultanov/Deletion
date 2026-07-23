<?php

declare(strict_types=1);

namespace Shared\Deletion\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Shared\Deletion\DeletionService;
use Shared\Deletion\Dto\{DependentGroupDto, OrderedPlanDto, RelationsDto};
use Shared\Deletion\Enum\DeletionCascade;
use Shared\Deletion\Exception\DeletionBlockedException;
use Shared\Deletion\Middleware\DeletionMiddlewareInterface;
use Throwable;

final class DeletionOrchestrator
{
    /**
     * @param iterable<DeletionMiddlewareInterface> $middlewares
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeletionService $analyzer,
        private readonly iterable $middlewares = [],
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @param bool $dryRun прогон без реальных мутаций (SQL/remove не выполняются)
     * @param bool $force  удалить, даже если анализ пометил сущность неудаляемой
     *
     * @throws DeletionBlockedException если !$force и есть жёсткие блокирующие зависимости (F1)
     */
    public function execute(object $root, bool $dryRun = false, bool $force = false): void
    {
        $relations = $this->plan($root);

        if (!$force && !$relations->canDelete) {
            throw new DeletionBlockedException($relations);
        }

        $plan = $this->buildOrderedPlan($root, $relations);
        $active = $this->activeMiddlewares($root);

        $this->em->wrapInTransaction(function () use ($plan, $root, $dryRun, $active): void {
            // 1) Detach M2M
            foreach ($plan->detach as $rel) {
                $this->notify($active, 'beforeDetachRelations', [$root::class, $rel['childClass'], $rel['childIds'], $rel, $root, $dryRun]);
                if (!$dryRun) {
                    $this->detachJoinRow($rel['joinTable'], $rel['joinColumn'], $rel['inverseJoinColumn'], $rel['parentId'], $rel['childIds']);
                }
                $this->notify($active, 'afterDetachRelations', [$root::class, $rel['childClass'], $rel['childIds'], $rel, $root, $dryRun]);
            }

            // 2) Delete children (топологически: сначала самые глубокие уровни, F7)
            foreach ($plan->delete as $del) {
                $this->notify($active, 'beforeDeleteChildren', [$del['class'], $del['ids'], $root, $dryRun]);
                if (!$dryRun) {
                    $this->deleteByIds($del['class'], $del['ids'], $del['idField']);
                }
                $this->notify($active, 'afterDeleteChildren', [$del['class'], $del['ids'], $root, $dryRun]);
            }

            // 3) Delete root
            $this->notify($active, 'beforeDeleteRoot', [$root, $dryRun]);
            if (!$dryRun) {
                $this->em->remove($root);
                $this->em->flush();
            }
            $this->notify($active, 'afterDeleteRoot', [$root, $dryRun]);
        });
    }

    public function plan(object $root): RelationsDto
    {
        return $this->analyzer->analyze($root);
    }

    public function getOrderedPlan(object $root): OrderedPlanDto
    {
        return $this->buildOrderedPlan($root, $this->plan($root));
    }

    private function buildOrderedPlan(object $root, RelationsDto $relations): OrderedPlanDto
    {
        /** @var array<string, array{ids: array<string, int|string>, idField: string, depth: int}> $deleteMap */
        $deleteMap = [];
        $detach = [];
        $visited = [];

        $this->collect($root, $relations, 0, $deleteMap, $detach, $visited);

        // Топологический порядок: удаляем сначала самые глубокие уровни (листья), затем ближе к корню (F7).
        uasort($deleteMap, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);

        $delete = [];
        foreach ($deleteMap as $class => $data) {
            $delete[] = ['class' => $class, 'ids' => array_values($data['ids']), 'idField' => $data['idField']];
        }

        return new OrderedPlanDto($delete, $detach);
    }

    /**
     * Рекурсивно собирает план удаления. Защищён visited-set от циклов/диамантов (F2), грузит детей
     * батчем на класс (F8).
     *
     * @param array<string, array{ids: array<string, int|string>, idField: string, depth: int}> $deleteMap
     * @param list<array<string, mixed>>                                                         $detach
     * @param array<string, true>                                                                $visited
     */
    private function collect(object $entity, RelationsDto $relations, int $depth, array &$deleteMap, array &$detach, array &$visited): void
    {
        $entityId = $this->identifierValue($entity);
        $key = $entity::class . '#' . ($entityId ?? 'null');
        if (isset($visited[$key])) {
            return;
        }
        $visited[$key] = true;

        // Detach текущего уровня — из правил карты + найденных detach-групп.
        foreach ($this->analyzer->getChildRelationRules($entity::class) as $rule) {
            if (!$rule->isJoinTable() || $rule->cascade !== DeletionCascade::DETACH_RELATIONS) {
                continue;
            }
            $group = $this->findGroup($relations->childrenDetach, $rule->childClass);
            if ($group !== null && $group->ids !== []) {
                $detach[] = [
                    'joinTable' => $rule->joinTable,
                    'joinColumn' => $rule->joinColumn,
                    'inverseJoinColumn' => $rule->inverseJoinColumn,
                    'parentId' => $entityId,
                    'childClass' => $rule->childClass,
                    'childIds' => $group->ids,
                ];
            }
        }

        // Каскадное удаление детей + рекурсия вглубь.
        foreach ($relations->childrenDelete as $group) {
            $idField = $this->identifierField($group->childClass);

            if (!isset($deleteMap[$group->childClass])) {
                $deleteMap[$group->childClass] = ['ids' => [], 'idField' => $idField, 'depth' => $depth + 1];
            } else {
                $deleteMap[$group->childClass]['depth'] = max($deleteMap[$group->childClass]['depth'], $depth + 1);
            }
            foreach ($group->ids as $cid) {
                $deleteMap[$group->childClass]['ids'][(string) $cid] = $cid;
            }

            foreach ($this->loadEntities($group->childClass, $idField, $group->ids) as $child) {
                $childRelations = $this->analyzer->analyze($child);
                $this->collect($child, $childRelations, $depth + 1, $deleteMap, $detach, $visited);
            }
        }
    }

    /**
     * @param list<DependentGroupDto> $groups
     */
    private function findGroup(array $groups, string $childClass): ?DependentGroupDto
    {
        foreach ($groups as $group) {
            if ($group->childClass === $childClass) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param list<DeletionMiddlewareInterface> $middlewares
     * @param list<mixed>                       $args
     */
    private function notify(array $middlewares, string $method, array $args): void
    {
        foreach ($middlewares as $mw) {
            try {
                $mw->{$method}(...$args);
            } catch (Throwable $e) {
                // Наблюдательный middleware не должен срывать удаление, но и теряться молча — нельзя (F6).
                $this->logger?->error('Deletion middleware failed', [
                    'middleware' => $mw::class,
                    'hook' => $method,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Оставляет только те middleware, что применимы к корню (F11).
     *
     * @return list<DeletionMiddlewareInterface>
     */
    private function activeMiddlewares(object $root): array
    {
        $active = [];
        foreach ($this->middlewares as $mw) {
            if ($mw->supports($root::class)) {
                $active[] = $mw;
            }
        }

        return $active;
    }

    private function identifierValue(object $entity): int|string|null
    {
        $ids = $this->em->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $first = array_values($ids)[0] ?? null;

        return is_int($first) || is_string($first) ? $first : null;
    }

    private function identifierField(string $class): string
    {
        $names = $this->em->getClassMetadata($class)->getIdentifierFieldNames();

        return $names[0] ?? 'id';
    }

    /**
     * Батч-загрузка сущностей по идентификатору (одним запросом на класс, F8).
     *
     * @param list<int|string> $ids
     *
     * @return list<object>
     */
    private function loadEntities(string $class, string $idField, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values($this->em->getRepository($class)->findBy([$idField => $ids]));
    }

    /**
     * @param int|string        $parentId
     * @param list<int|string>  $childIds
     */
    private function detachJoinRow(string $joinTable, string $joinColumn, string $inverseJoinColumn, int|string $parentId, array $childIds): void
    {
        if ($childIds === []) {
            return;
        }

        $conn = $this->em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $placeholders = implode(',', array_fill(0, count($childIds), '?'));

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ? AND %s IN (%s)',
            $platform->quoteIdentifier($joinTable),
            $platform->quoteIdentifier($joinColumn),
            $platform->quoteIdentifier($inverseJoinColumn),
            $placeholders
        );

        $conn->executeStatement($sql, array_merge([$parentId], $childIds));
    }

    /**
     * Массовое удаление по идентификатору сущности (F9). Замечание: bulk DQL DELETE не поднимает
     * lifecycle-события Doctrine и orphanRemoval (F14) — это осознанный компромисс ради производительности.
     *
     * @param list<int|string> $ids
     */
    private function deleteByIds(string $entityClass, array $ids, string $idField): void
    {
        if ($ids === []) {
            return;
        }

        $qb = $this->em->createQueryBuilder();
        $qb->delete($entityClass, 'e')
            ->where($qb->expr()->in(sprintf('e.%s', $idField), ':ids'))
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
