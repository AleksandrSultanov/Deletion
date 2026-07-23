<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests;

use PHPUnit\Framework\TestCase;
use Shared\Deletion\Middleware\MetricsDeletionMiddleware;
use Shared\Deletion\Tests\Fixture\ScalarParentFixture;
use Shared\Deletion\Tests\Support\InMemoryMetricsCollector;

/**
 * Тесты MetricsDeletionMiddleware. Часы (clock) детерминированы — подаём заранее заданную
 * последовательность монотонных меток (в секундах), поэтому тайминги проверяемы точно.
 */
final class MetricsDeletionMiddlewareTest extends TestCase
{
    public function testFullOperationEmitsDurationsAndCounters(): void
    {
        $metrics = new InMemoryMetricsCollector();
        // Последовательность вызовов now() по ходу одной операции (см. трассировку в шапке middleware):
        //  op.start, detach.start, detach.stop, children.start, children.stop, root.start, root.stop, op.end
        $clock = $this->clock([0.0, 0.0, 0.010, 0.010, 0.040, 0.040, 0.050, 0.050]);
        $mw = new MetricsDeletionMiddleware($metrics, $clock);
        $root = new ScalarParentFixture();

        $relation = ['joinTable' => 'rel', 'joinColumn' => 'p', 'inverseJoinColumn' => 'c'];
        $mw->beforeDetachRelations('ParentNs\\Parent', 'ChildNs\\Child', [1, 2], $relation, $root, false);
        $mw->afterDetachRelations('ParentNs\\Parent', 'ChildNs\\Child', [1, 2], $relation, $root, false);
        $mw->beforeDeleteChildren('ChildNs\\Child', [1, 2, 3], $root, false);
        $mw->afterDeleteChildren('ChildNs\\Child', [1, 2, 3], $root, false);
        $mw->beforeDeleteRoot($root, false);
        $mw->afterDeleteRoot($root, false);

        // Тайминги фаз (мс)
        self::assertEqualsWithDelta(10.0, $metrics->firstTiming('deletion.detach.duration'), 0.001);
        self::assertEqualsWithDelta(30.0, $metrics->firstTiming('deletion.children.duration'), 0.001);
        self::assertEqualsWithDelta(10.0, $metrics->firstTiming('deletion.root.duration'), 0.001);
        self::assertEqualsWithDelta(50.0, $metrics->firstTiming('deletion.operation.duration'), 0.001);

        // Счётчики объёма
        self::assertSame(2, $metrics->counterTotal('deletion.detach.relations'));
        self::assertSame(3, $metrics->counterTotal('deletion.children.deleted'));
        self::assertSame(1, $metrics->counterTotal('deletion.root.deleted'));
        self::assertSame(1, $metrics->counterTotal('deletion.operation.completed'));
    }

    public function testDryRunEmitsNoMetrics(): void
    {
        $metrics = new InMemoryMetricsCollector();
        $mw = new MetricsDeletionMiddleware($metrics, $this->clock([0.0, 0.0, 0.010]));
        $root = new ScalarParentFixture();

        $mw->beforeDeleteChildren('ChildNs\\Child', [1, 2, 3], $root, true);
        $mw->afterDeleteChildren('ChildNs\\Child', [1, 2, 3], $root, true);
        $mw->beforeDeleteRoot($root, true);
        $mw->afterDeleteRoot($root, true);

        self::assertSame([], $metrics->counters);
        self::assertSame([], $metrics->timings);
    }

    public function testFlushPendingReportsIncompletePhases(): void
    {
        $metrics = new InMemoryMetricsCollector();
        // Операция «оборвалась»: был beforeDeleteRoot, но afterDeleteRoot не пришёл (напр., откат транзакции).
        $mw = new MetricsDeletionMiddleware($metrics, $this->clock([0.0, 0.0]));

        $mw->beforeDeleteRoot(new ScalarParentFixture(), false);
        $mw->flushPending();

        self::assertSame(1, $metrics->counterTotal('deletion.phase.incomplete'));
        // После сброса состояние чистое: повторный flush ничего не эмитит.
        $before = count($metrics->counters);
        $mw->flushPending();
        self::assertCount($before, $metrics->counters);
    }

    public function testTagsCarryShortClassNames(): void
    {
        $metrics = new InMemoryMetricsCollector();
        $mw = new MetricsDeletionMiddleware($metrics, $this->clock([0.0, 0.0, 0.005, 0.005]));
        $root = new ScalarParentFixture();

        $mw->beforeDeleteRoot($root, false);
        $mw->afterDeleteRoot($root, false);

        $rootDeleted = array_values(array_filter(
            $metrics->counters,
            static fn (array $c): bool => $c['metric'] === 'deletion.root.deleted'
        ));
        self::assertSame('ScalarParentFixture', $rootDeleted[0]['tags']['root']);
    }

    /**
     * Детерминированные монотонные часы: возвращают значения из очереди по одному на вызов.
     *
     * @param list<float> $ticks
     *
     * @return callable(): float
     */
    private function clock(array $ticks): callable
    {
        $i = 0;

        return static function () use (&$i, $ticks): float {
            $value = $ticks[$i] ?? end($ticks);
            $i++;

            return (float) $value;
        };
    }
}
