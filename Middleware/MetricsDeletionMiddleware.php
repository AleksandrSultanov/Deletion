<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

/**
 * Middleware для сбора метрик по операции каскадного удаления.
 *
 * ЧТО ИЗМЕРЯЕМ (инженерное решение):
 *   1. Длительность каждой фазы — detach / deleteChildren / deleteRoot (тайминг между before* и after*).
 *   2. Длительность всей операции — от первого хука до afterDeleteRoot.
 *   3. Счётчики объёма: сколько связей отвязано, сколько детей удалено (в разрезе класса), удалён ли root.
 *   4. Факт завершения операции (success-прокси) — на afterDeleteRoot.
 *
 * ВАЖНЫЕ ОГРАНИЧЕНИЯ КОНТРАКТА (см. REVIEW.md, п. 3.5–3.6):
 *   - DeletionOrchestrator::notify() ЛОВИТ И ГЛУШИТ все исключения middleware. Поэтому метрики никогда
 *     не должны бросать наружу — реализация не выбрасывает исключений сама и не полагается на то, что
 *     исключение «дойдёт» до оркестратора.
 *   - Если удаление падает МЕЖДУ before* и after*, парный after* не вызовется, и таймер фазы «повиснет».
 *     Мы храним висящие таймеры и умеем их сбросить/финализировать (см. flushPending()). Ядро сейчас
 *     не вызывает flushPending(), поэтому это «best-effort»: метод оставлен как точка расширения на
 *     случай, когда оркестратор начнёт финализировать middleware в finally-блоке.
 *   - В интерфейсе НЕТ хука onError/onFailure, поэтому надёжно отличить успех от падения нельзя.
 *     Мы считаем операцию завершённой (deletion.operation.completed) по afterDeleteRoot. Незавершённые
 *     операции детектируются косвенно — по «повисшим» таймерам при следующем старте.
 *   - supports() оркестратором игнорируется (используется method_exists), поэтому возвращаем true и
 *     фиксируем это как известное поведение ядра.
 */
final class MetricsDeletionMiddleware implements DeletionMiddlewareInterface
{
    /** Префикс всех метрик. */
    private const NS = 'deletion';

    /**
     * Висящие таймеры фаз: ключ фазы => монотонная метка старта (секунды).
     *
     * @var array<string, float>
     */
    private array $pending = [];

    /** Монотонная метка старта всей операции (секунды), либо null, если операция ещё не началась. */
    private ?float $operationStartedAt = null;

    /**
     * @param MetricsCollectorInterface $metrics сток метрик (StatsD/Prometheus/DataDog/in-memory)
     * @param (callable(): float)       $clock   монотонные часы в СЕКУНДАХ; по умолчанию hrtime.
     *                                           Вынесен в конструктор ради детерминизма в тестах.
     */
    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
        private $clock = null
    ) {
        // Монотонные часы (hrtime) не подвержены переводу системного времени/NTP.
        $this->clock ??= static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * Оркестратор этот метод игнорирует (см. заметку в шапке класса). Метрики применимы к любой
     * сущности, поэтому возвращаем true.
     */
    public function supports(string $entityClass): bool
    {
        return true;
    }

    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $this->ensureOperationStarted();
        $this->startPhase($this->phaseKey('detach', $parentClass, $childClass));
    }

    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $tags = ['parent' => $this->shortName($parentClass), 'child' => $this->shortName($childClass)];

        $this->stopPhase($this->phaseKey('detach', $parentClass, $childClass), self::NS . '.detach.duration', $tags);
        $this->metrics->increment(self::NS . '.detach.relations', count($childIds), $tags);
    }

    public function beforeDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $this->ensureOperationStarted();
        $this->startPhase($this->phaseKey('children', $childClass));
    }

    public function afterDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $tags = ['child' => $this->shortName($childClass)];

        $this->stopPhase($this->phaseKey('children', $childClass), self::NS . '.children.duration', $tags);
        $this->metrics->increment(self::NS . '.children.deleted', count($childIds), $tags);
    }

    public function beforeDeleteRoot(object $root): void
    {
        $this->ensureOperationStarted();
        $this->startPhase($this->phaseKey('root', $root::class));
    }

    public function afterDeleteRoot(object $root): void
    {
        $rootClass = $root::class;
        $tags = ['root' => $this->shortName($rootClass)];

        $this->stopPhase($this->phaseKey('root', $rootClass), self::NS . '.root.duration', $tags);
        $this->metrics->increment(self::NS . '.root.deleted', 1, $tags);

        // Операция дошла до конца — фиксируем её длительность и факт завершения.
        if ($this->operationStartedAt !== null) {
            $elapsedMs = ($this->now() - $this->operationStartedAt) * 1000.0;
            $this->metrics->timing(self::NS . '.operation.duration', $elapsedMs, $tags);
            $this->metrics->increment(self::NS . '.operation.completed', 1, $tags);
        }

        $this->reset();
    }

    /**
     * Точка расширения: финализировать «повисшие» таймеры (например, из finally-блока оркестратора,
     * когда транзакция откатилась и after* не были вызваны). Эмитит для каждой незавершённой фазы
     * счётчик deletion.phase.incomplete и обнуляет состояние.
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
        // Если предыдущая операция не завершилась (не было afterDeleteRoot), её таймеры «повисли».
        // Фиксируем их как incomplete, прежде чем начинать новую.
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
            // Парный before* не пришёл — фаза без старта; тайминг посчитать нельзя.
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

    /**
     * Короткое имя класса для тега метрики (без FQCN — чтобы не плодить кардинальность и не течь путями).
     */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
