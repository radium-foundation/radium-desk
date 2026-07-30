<?php

namespace App\Services\ConversationWorkspace;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\BonvoiceCallLinkType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NewContactIntent;
use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\IncidentBonvoiceCallLink;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerIntakeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ensures an unknown answered caller has an inquiry incident so Customer360
 * can open into the Conversation Workspace.
 */
class ConversationWorkspaceBootstrapService
{
    public function __construct(
        private readonly CustomerIntakeService $customerIntakeService,
        private readonly ConversationWorkspaceSessionService $sessionService,
    ) {}

    public function ensureIncidentForUnknownAnsweredCall(
        BonvoiceCallAlert $alert,
        BonvoiceCallEvent $event,
        User $agent,
    ): ?BonvoiceCallAlert {
        if (! config('conversation_workspace.enabled')) {
            return null;
        }

        if (! config('conversation_workspace.auto_create_inquiry_on_answer')) {
            return null;
        }

        if ($alert->alert_type !== BonvoiceCallAlertType::UnknownCaller) {
            return null;
        }

        if ($alert->incident_id !== null) {
            return $alert;
        }

        $phone = trim((string) ($alert->customer_phone ?? $event->customer_phone ?? ''));

        if ($phone === '') {
            return null;
        }

        try {
            $incident = $this->findOpenInquiryIncidentForPhone($phone)
                ?? $this->customerIntakeService->createNewContact(
                    user: $agent,
                    intent: NewContactIntent::GeneralSupport,
                    source: IncidentSource::Call,
                    customerName: null,
                    phone: $phone,
                    serialNumber: null,
                    product: null,
                    notes: null,
                    highPriority: false,
                    assignOnCreate: false,
                );

            if ($incident->assigned_to_user_id === null) {
                $incident->forceFill([
                    'assigned_to_user_id' => $agent->id,
                    'updated_by' => $agent->id,
                ])->save();
            }

            $alert->forceFill([
                'incident_id' => $incident->id,
                'order_id' => $incident->order_id,
            ])->save();

            $this->sessionService->firstOrCreateForIncident(
                $incident,
                $agent,
                $event->call_id,
            );

            $this->linkCallIfMissing($incident, $event);

            $alert->unsetRelations();
            $alert->load(['order', 'incident', 'user']);

            return $alert;
        } catch (Throwable $exception) {
            Log::error('[Conversation Workspace] Failed to bootstrap inquiry for unknown caller', [
                'call_id' => $event->call_id,
                'alert_id' => $alert->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function shouldOpenConversationWorkspace(BonvoiceCallAlert $alert): bool
    {
        if (! config('conversation_workspace.enabled')) {
            return false;
        }

        $alert->load(['order', 'incident']);

        $order = $alert->order;
        $incident = $alert->incident;

        if ($order === null || $incident === null) {
            return false;
        }

        if (! $order->isInquiryOrder()) {
            return false;
        }

        return $incident->inquiry_origin_order_id === null;
    }

    private function findOpenInquiryIncidentForPhone(string $phone): ?Incident
    {
        $activeStatuses = array_map(
            static fn (IncidentStatus $status): string => $status->value,
            IncidentStatus::operationallyActive(),
        );

        $order = Order::query()
            ->where('customer_phone', $phone)
            ->where('order_id', 'like', 'INQ-%')
            ->orderByDesc('id')
            ->first();

        if ($order === null) {
            return null;
        }

        return $order->incidents()
            ->whereIn('status', $activeStatuses)
            ->whereNull('inquiry_origin_order_id')
            ->orderByDesc('id')
            ->first();
    }

    private function linkCallIfMissing(Incident $incident, BonvoiceCallEvent $event): void
    {
        try {
            IncidentBonvoiceCallLink::query()->create([
                'incident_id' => $incident->id,
                'bonvoice_call_event_id' => $event->id,
                'call_id' => $event->call_id,
                'link_type' => BonvoiceCallLinkType::Answered,
                'linked_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $errorCode = (string) ($exception->errorInfo[1] ?? '');

            if (! in_array($errorCode, ['1062', '19', '2067', '1555'], true)) {
                throw $exception;
            }
        }
    }
}
