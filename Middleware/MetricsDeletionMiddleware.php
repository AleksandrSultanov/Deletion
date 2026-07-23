<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

/**
 * Middleware для сбора метрик по операции каскадного удаления.
 *
 * ЧТО ИЗМЕРЯЕМ:
 *   1. Длительность каждой фазы — detach / deleteChildren / deleteRoot (тайминг между before* и after*).
 *   2. Длительность всей операции — от первого хука до afterDeleteRoot.
 *   3. Счётчики объёма: сколько связей отвязано, сколько детей удалено (в разрезе класса), удалён ли root.
 *   4. Факт завершения операции (success-прокси) — на afterDeleteRoot.
 *
 * DRY-RUN: при $dryRun метрики НЕ пишутся (F10) — прогонная операция не должна попадать в статистику
 * как реальное удаление. Пропуск симметричен (и before*, и after*), поэтому таймеры не «повисают».
 *
 * ОГРАНИЧЕНИЯ КОНТРАКТА:
 *   - Оркестратор глушит исключения middleware, поэтому реализация сама не бросает наружу.
 *   - Если удаление падает между before* и after*, парный after* не вызовется, и таймер «повиснет».
 *     Такие таймеры финализируются через flushPending() (точка расширения для finally-блока оркестратора)
 *     либо косвенно — при старте следующей операции.
 *   - Хука onError в интерфейсе нет, поэтому завершение (completed) считается по afterDeleteRoot.
 */
final class MetricsDeletionMiddleware implements DeletionMiddlewareInterface
{
    private const NS = 'deletion';

    /** @var array<string, float> висящие таймеры фаз: ключ => монотонная метка старта (сек) */
    private array $pending = [];

    private ?float $operationStartedAt = null;

    /**
     * @param (callable(): float) $clock монотонные часы в СЕКУНДАХ; по умолчанию hrtime (для тестов — инъекция)
     */
    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
        private $clock = null
    ) {
        $this->clock ??= static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /** Метрики применимы к любой сущности. */
    public function supports(string $entityClass): bool
    {
        return true;
    }

    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $this->ensureOperationStarted();
        $this->startPhase($this->phaseKey('detach', $parentClass, $childClass));
    }

    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $tags = ['parent' => $this->shortName($parentClass), 'child' => $this->shortName($childClass)];

        $this->stopPhase($this->phaseKey('detach', $parentClass, $childClass), self::NS . '.detach.duration', $tags);
        $this->metrics->increment(self::NS . '.detach.relations', count($childIds), $tags);
    }

    public function beforeDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $this->ensureOperationStarted();
        $this->startPhase($this->phaseKey('children', $childClass));
    }

    public function afterDeleteChildren(string $childClass, array $childIds, object $root, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $tags = ['child' => $this->shortName($childClass)];

        $this->stopPhase($this->phaseKey('children', $childClass), self::NS . '.children.duration', $tags);
        $this->metrics->increment(self::NS . '.children.deleted', count($childIds), $tags);
    }

    public function beforeDeleteRoot(object $root, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $this->ensureOperationStarted();
        $this->startPhase($this->phaseKey('root', $root::class));
    }

    public function afterDeleteRoot(object $root, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $rootClass = $root::class;
        $tags = ['root' => $this->shortName($rootClass)];

        $this->stopPhase($this->phaseKey('root', $rootClass), self::NS . '.root.duration', $tags);
        $this->metrics->increment(self::NS . '.root.deleted', 1, $tags);

        if ($this->operationStartedAt !== null) {
            $elapsedMs = ($this->now() - $this->operationStartedAt) * 1000.0;
            $this->metrics->timing(self::NS . '.operation.duration', $elapsedMs, $tags);
            $this->metrics->increment(self::NS . '.operation.completed', 1, $tags);
        }

        $this->reset();
    }

    /**
     * Финализировать «повисшие» таймеры (например, из finally-блока оркестратора после отката транзакции):
     * на каждую незавершённую фазу эмитит deletion.phase.incomplete и обнуляет состояние.
     */
    public function flushPending(): void
    {
        foreach (array_keys($this->pending) as $key) {
            $this->metrics->increment(self::NS . '.phase.incomplete', 1, ['phase' => $key]);
        }

        $this->reset();
    }

    private function ensureOperationStarted(): void
    {
        if ($this->operationStartedAt !== null && $this->pending !== []) {
            $this->flushPending();
        }

        $this->operationStartedAt ??= $this->now();
    }

    private function startPhase(string $key): void
    {
        $this->pending[$key] = $this->now();
    }

    /**
     * @param array<string, string|int> $tags
     */
    private function stopPhase(string $key, string $metric, array $tags): void
    {
        if (!isset($this->pending[$key])) {
            return;
        }

        $elapsedMs = ($this->now() - $this->pending[$key]) * 1000.0;
        unset($this->pending[$key]);
        $this->metrics->timing($metric, $elapsedMs, $tags);
    }

    private function phaseKey(string $phase, string ...$parts): string
    {
        return $phase . ':' . implode('>', $parts);
    }

    private function reset(): void
    {
        $this->pending = [];
        $this->operationStartedAt = null;
    }

    private function now(): float
    {
        return ($this->clock)();
    }

    /** Короткое имя класса для тега (без FQCN — меньше кардинальность, не течём путями). */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
