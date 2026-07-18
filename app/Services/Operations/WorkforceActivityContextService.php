<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WorkforceActivityContextService
{
    public const EVENT_ORDER_VIEWED = 'order.viewed';

    public const EVENT_SERVICE_CASE_VIEWED = 'service_case.viewed';

    public function __construct(
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordOrderViewed(User $user, Order $order, ?Request $request = null): void
    {
        if (! $this->shouldCaptureNavigation($request)) {
            return;
        }

        $session = $this->openTrackedSession($user);

        if ($session === null) {
            return;
        }

        if ($this->shouldSkipViewEvent(
            session: $session,
            event: self::EVENT_ORDER_VIEWED,
            auditable: $order,
            sessionEntityId: $session->current_order_id,
            sessionViewedAt: $session->last_order_viewed_at,
        )) {
            if ((int) $session->current_order_id !== (int) $order->id) {
                $this->updateSessionContext($session, [
                    'current_order_id' => $order->id,
                ]);
            }

            return;
        }

        $at = now();

        $this->auditLogService->log(
            userId: $user->id,
            event: self::EVENT_ORDER_VIEWED,
            auditable: $order,
            newValues: [
                'order_id' => $order->order_id,
                'work_session_id' => $session->id,
            ],
            request: $request,
        );

        $this->updateSessionContext($session, [
            'current_order_id' => $order->id,
            'last_order_viewed_at' => $at,
            'last_business_action' => self::EVENT_ORDER_VIEWED,
            'last_business_action_at' => $at,
        ]);
    }

    public function recordServiceCaseViewed(User $user, Incident $incident, ?Request $request = null): void
    {
        if (! $this->shouldCaptureNavigation($request)) {
            return;
        }

        $session = $this->openTrackedSession($user);

        if ($session === null) {
            return;
        }

        if ($this->shouldSkipViewEvent(
            session: $session,
            event: self::EVENT_SERVICE_CASE_VIEWED,
            auditable: $incident,
            sessionEntityId: $session->current_incident_id,
            sessionViewedAt: $session->last_incident_viewed_at,
        )) {
            if ((int) $session->current_incident_id !== (int) $incident->id) {
                $this->updateSessionContext($session, [
                    'current_incident_id' => $incident->id,
                    'current_order_id' => $incident->order_id,
                ]);
            }

            return;
        }

        $at = now();

        $this->auditLogService->log(
            userId: $user->id,
            event: self::EVENT_SERVICE_CASE_VIEWED,
            auditable: $incident,
            newValues: [
                'reference_no' => $incident->display_reference ?: $incident->reference_no,
                'order_id' => $incident->order_id,
                'work_session_id' => $session->id,
            ],
            request: $request,
        );

        $this->updateSessionContext($session, [
            'current_incident_id' => $incident->id,
            'current_order_id' => $incident->order_id,
            'last_incident_viewed_at' => $at,
            'last_business_action' => self::EVENT_SERVICE_CASE_VIEWED,
            'last_business_action_at' => $at,
        ]);
    }

    public function touchBusinessAction(User $user, string $action, ?Carbon $at = null): void
    {
        $session = $this->presenceEngine->openSessionFor($user);

        if ($session === null) {
            return;
        }

        $at ??= now();

        $this->updateSessionContext($session, [
            'last_business_action' => $action,
            'last_business_action_at' => $at,
        ]);
    }

    private function shouldSkipViewEvent(
        WorkSession $session,
        string $event,
        Model $auditable,
        mixed $sessionEntityId,
        mixed $sessionViewedAt,
    ): bool {
        $entityId = (int) $auditable->getKey();
        $cutoff = now()->subSeconds($this->viewCooldownSeconds());

        if ((int) $sessionEntityId === $entityId
            && $sessionViewedAt instanceof Carbon
            && $sessionViewedAt->gte($cutoff)
        ) {
            return true;
        }

        if ((int) $sessionEntityId === $entityId) {
            return false;
        }

        return AuditLog::query()
            ->where('user_id', $session->user_id)
            ->where('event', $event)
            ->where('auditable_type', $auditable->getMorphClass())
            ->where('auditable_id', $entityId)
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }

    private function openTrackedSession(User $user): ?WorkSession
    {
        if (! $this->roleService->isAttendanceTracked($user)) {
            return null;
        }

        return $this->presenceEngine->openSessionFor($user)
            ?? $this->presenceEngine->startSession($user);
    }

    /**
     * @param  array<string, int|string|Carbon|null>  $attributes
     */
    private function updateSessionContext(WorkSession $session, array $attributes): void
    {
        WorkSession::query()
            ->whereKey($session->id)
            ->update($attributes);

        $session->forceFill($attributes);
    }

    private function viewCooldownSeconds(): int
    {
        return max(0, (int) config('presence.view_event_cooldown_seconds', 60));
    }

    private function shouldCaptureNavigation(?Request $request): bool
    {
        if ($request === null) {
            return true;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->ajax() || $request->pjax()) {
            return false;
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return false;
        }

        if ($request->header('X-Livewire') !== null) {
            return false;
        }

        $purpose = strtolower((string) ($request->header('Purpose') ?? $request->header('Sec-Purpose') ?? ''));

        if ($purpose === 'prefetch') {
            return false;
        }

        return true;
    }
}
