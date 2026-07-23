<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Support;

use Shared\Deletion\Middleware\MetricsCollectorInterface;

/**
 * Простой in-memory сток метрик для тестов: копит счётчики и тайминги, чтобы их можно было проверить.
 */
final class InMemoryMetricsCollector implements MetricsCollectorInterface
{
    /** @var list<array{metric: string, value: int, tags: array<string, string|int>}> */
    public array $counters = [];

    /** @var list<array{metric: string, ms: float, tags: array<string, string|int>}> */
    public array $timings = [];

    public function increment(string $metric, int $value = 1, array $tags = []): void
    {
        $this->counters[] = ['metric' => $metric, 'value' => $value, 'tags' => $tags];
    }

    public function timing(string $metric, float $milliseconds, array $tags = []): void
    {
        $this->timings[] = ['metric' => $metric, 'ms' => $milliseconds, 'tags' => $tags];
    }

    /** Суммарное значение счётчика по имени метрики. */
    public function counterTotal(string $metric): int
    {
        $sum = 0;
        foreach ($this->counters as $c) {
            if ($c['metric'] === $metric) {
                $sum += $c['value'];
            }
        }

        return $sum;
    }

    /** Первый зафиксированный тайминг по имени метрики (в мс) или null. */
    public function firstTiming(string $metric): ?float
    {
        foreach ($this->timings as $t) {
            if ($t['metric'] === $metric) {
                return $t['ms'];
            }
        }

        return null;
    }
}
