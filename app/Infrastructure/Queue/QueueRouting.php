<?php

namespace App\Infrastructure\Queue;

final class QueueRouting
{
    public static function critical(): string
    {
        return (string) config('queue_routing.queues.critical', 'critical');
    }

    public static function notifications(): string
    {
        return (string) config('queue_routing.queues.notifications', 'notifications');
    }

    public static function maintenance(): string
    {
        return (string) config('queue_routing.queues.maintenance', 'maintenance');
    }

    public static function default(): string
    {
        return (string) config('queue_routing.queues.default', 'default');
    }

    /**
     * Comma-separated queue list for queue:work --queue=
     */
    public static function workerOrder(): string
    {
        /** @var list<string> $order */
        $order = config('queue_routing.worker_order', [
            'critical',
            'notifications',
            'default',
            'maintenance',
        ]);

        $names = [];

        foreach ($order as $key) {
            $names[] = (string) config("queue_routing.queues.{$key}", $key);
        }

        return implode(',', $names);
    }
}
