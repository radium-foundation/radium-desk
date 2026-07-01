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
    public function create(
        Incident $incident,
        User $actor,
        bool $serialNumberUnavailable,
        bool $referenceNumberUnavailable,
        ServiceCaseCloseExceptionReason $reason,
        ?string $reasonCustom,
        bool $notifyWhatsapp,
        bool $notifyEmail,
        ?Request $request = null,
    ): ServiceCaseCloseException {
        if ($reason === ServiceCaseCloseExceptionReason::Other
            && ! filled(trim((string) $reasonCustom))) {
            throw ValidationException::withMessages([
                'exception_reason_custom' => 'A custom remark is required when Other is selected.',
            ]);
        }

        return DB::transaction(function () use (
            $incident,
            $actor,
            $serialNumberUnavailable,
            $referenceNumberUnavailable,
            $reason,
            $reasonCustom,
            $notifyWhatsapp,
            $notifyEmail,
            $request,
        ): ServiceCaseCloseException {
            $exception = ServiceCaseCloseException::query()->create([
                'incident_id' => $incident->id,
                'exception_id' => $this->exceptionIdService->generate(),
                'serial_number_unavailable' => $serialNumberUnavailable,
                'reference_number_unavailable' => $referenceNumberUnavailable,
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
                    'reason' => $exception->reason->value,
                    'reason_label' => $exception->displayReason(),
                    'serial_number_unavailable' => $serialNumberUnavailable,
                    'reference_number_unavailable' => $referenceNumberUnavailable,
                ],
                request: $request,
            );

            return $exception;
        });
    }
}
