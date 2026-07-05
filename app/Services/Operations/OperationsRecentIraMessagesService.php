<?php

namespace App\Services\Operations;

class OperationsRecentIraMessagesService
{
    public function __construct(
        private readonly IraNotificationService $notificationService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 15): array
    {
        return $this->notificationService->recentForDashboard($limit);
    }
}
