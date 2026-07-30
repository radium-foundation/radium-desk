<?php

namespace App\Support\ConversationWorkspace;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Support\Customer360\Customer360AgentNamePresenter;

final class ConversationWorkspacePresenter
{
    /**
     * @param  array<string, mixed>  $sessionView
     * @return array<string, mixed>
     */
    public function present(
        Incident $incident,
        Order $order,
        array $sessionView,
        bool $canLinkOrder,
        ?string $callId = null,
        ?User $viewer = null,
    ): array {
        $phone = trim((string) ($order->customer_phone ?? $incident->recovery_phone ?? ''));
        $agentName = Customer360AgentNamePresenter::displayFirstName(
            $incident->assignee?->name ?? $viewer?->name,
            $incident->assignee?->first_name ?? $viewer?->first_name,
        );

        return [
            'active' => true,
            'incident_id' => $incident->id,
            'call_id' => $callId ?? ($sessionView['call_id'] ?? null),
            'phone' => $phone !== '' ? $phone : null,
            'agent_name' => $agentName,
            'can_link_order' => $canLinkOrder,
            'update_url' => route('dashboard.service-cases.conversation-workspace.update', $incident),
            'session' => $sessionView,
        ];
    }
}
