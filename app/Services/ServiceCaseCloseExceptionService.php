<?php

namespace App\Services;

use App\Enums\ServiceCaseCloseExceptionReason;
use App\Models\Incident;
use App\Models\ServiceCaseCloseException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseCloseExceptionService
{
    public function __construct(
        private readonly ServiceCaseCloseExceptionIdService $exceptionIdService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createSerialException(
        Incident $incident,
        User $actor,
        ServiceCaseCloseExceptionReason $reason,
        ?string $reasonCustom,
        bool $notifyWhatsapp,
        bool $notifyEmail,
        ?Request $request = null,
    ): ServiceCaseCloseException {
        return $this->create(
            incident: $incident,
            actor: $actor,
            type: 'serial',
            reason: $reason,
            reasonCustom: $reasonCustom,
            notifyWhatsapp: $notifyWhatsapp,
            notifyEmail: $notifyEmail,
            request: $request,
        );
    }

    /**
     * @throws ValidationException
     */
    public function createReferenceException(
        Incident $incident,
        User $actor,
        ServiceCaseCloseExceptionReason $reason,
        ?string $reasonCustom,
        bool $notifyWhatsapp,
        bool $notifyEmail,
        ?Request $request = null,
    ): ServiceCaseCloseException {
        return $this->create(
            incident: $incident,
            actor: $actor,
            type: 'reference',
            reason: $reason,
            reasonCustom: $reasonCustom,
            notifyWhatsapp: $notifyWhatsapp,
            notifyEmail: $notifyEmail,
            request: $request,
        );
    }

    /**
     * @throws ValidationException
     */
    private function create(
        Incident $incident,
        User $actor,
        string $type,
        ServiceCaseCloseExceptionReason $reason,
        ?string $reasonCustom,
        bool $notifyWhatsapp,
        bool $notifyEmail,
        ?Request $request = null,
    ): ServiceCaseCloseException {
        if ($reason === ServiceCaseCloseExceptionReason::Other
            && ! filled(trim((string) $reasonCustom))) {
            $field = $type === 'serial' ? 'serial_exception_reason_custom' : 'reference_exception_reason_custom';

            throw ValidationException::withMessages([
                $field => 'A custom remark is required when Other is selected.',
            ]);
        }

        return DB::transaction(function () use (
            $incident,
            $actor,
            $type,
            $reason,
            $reasonCustom,
            $notifyWhatsapp,
            $notifyEmail,
            $request,
        ): ServiceCaseCloseException {
            $exceptionId = $type === 'serial'
                ? $this->exceptionIdService->generateSerial()
                : $this->exceptionIdService->generateReference();

            if ($type === 'serial') {
                $order = $incident->order;

                if ($order !== null) {
                    $order->update(['serial_number' => $exceptionId]);
                }
            } else {
                $incident->update(['reference_no' => $exceptionId]);
            }

            $exception = ServiceCaseCloseException::query()->create([
                'incident_id' => $incident->id,
                'exception_id' => $exceptionId,
                'serial_number_unavailable' => $type === 'serial',
                'reference_number_unavailable' => $type === 'reference',
                'reason' => $reason,
                'reason_custom' => $reason === ServiceCaseCloseExceptionReason::Other
                    ? trim((string) $reasonCustom)
                    : null,
                'notify_whatsapp' => $notifyWhatsapp,
                'notify_email' => $notifyEmail,
                'created_by' => $actor->id,
            ]);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'service_case.close_exception',
                auditable: $incident,
                oldValues: null,
                newValues: [
                    'exception_id' => $exception->exception_id,
                    'type' => $type,
                    'reason' => $exception->reason->value,
                    'reason_label' => $exception->displayReason(),
                ],
                request: $request,
            );

            return $exception;
        });
    }
}
