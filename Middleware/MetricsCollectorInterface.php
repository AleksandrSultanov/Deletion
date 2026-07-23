<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

/**
 * Абстракция стока метрик (StatsD-подобная).
 *
 * Намеренно не привязана к конкретной реализации (Prometheus / StatsD / DataDog),
 * чтобы {@see MetricsDeletionMiddleware} не зависел от инфраструктуры.
 * В тестах используется in-memory реализация.
 */
interface MetricsCollectorInterface
{
    /**
     * Счётчик: увеличить метрику на $value.
     *
     * @param array<string, string|int> $tags
     */
    public function increment(string $metric, int $value = 1, array $tags = []): void;

    /**
     * Тайминг: зафиксировать длительность операции в миллисекундах.
     *
     * @param array<string, string|int> $tags
     */
    public function timing(string $metric, float $milliseconds, array $tags = []): void;
}
