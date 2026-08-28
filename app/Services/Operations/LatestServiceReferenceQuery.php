<?php

namespace App\Services\Operations;

use App\Data\Operations\LatestServiceReference;
use App\Models\AuditLog;
use App\Models\User;

class LatestServiceReferenceQuery
{
    public function latest(): ?LatestServiceReference
    {
        $logs = AuditLog::query()
            ->with('user')
            ->where('event', 'service_reference.assigned')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursor();

        foreach ($logs as $log) {
            $serviceReference = trim((string) ($log->new_values['transaction_id'] ?? ''));

            if ($serviceReference === '') {
                continue;
            }

            $addedAt = $log->created_at;

            if ($addedAt === null) {
                continue;
            }

            return new LatestServiceReference(
                serviceReference: $serviceReference,
                agentName: $this->agentName($log->user),
                addedAt: $addedAt,
            );
        }

        return null;
    }

    private function agentName(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        $firstName = $user->firstName();

        return $firstName !== '' ? $firstName : trim((string) $user->name);
    }
}
