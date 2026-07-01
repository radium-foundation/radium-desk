<?php

namespace App\Services\Interakt;

use App\Data\InteraktSendResult;
use App\Data\WhatsAppTemplateConfiguration;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\AuditLogService;
use App\Services\RemarkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppTemplateDispatchCompletionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly RemarkService $remarkService,
    ) {}

    public function markSent(
        WhatsAppTemplateDispatch $dispatch,
        InteraktSendResult $result,
        WhatsAppTemplateConfiguration $configuration,
        ?Request $request = null,
    ): WhatsAppTemplateDispatch {
        return DB::transaction(function () use ($dispatch, $result, $configuration, $request): WhatsAppTemplateDispatch {
            $dispatch->update([
                'status' => WhatsAppTemplateDispatchStatus::Sent,
                'interakt_message_id' => $result->messageId,
                'error_message' => null,
                'dispatched_at' => now(),
            ]);

            $dispatch->loadMissing(['incident', 'triggeredBy']);

            $this->auditLogService->log(
                userId: $dispatch->triggered_by_user_id,
                event: 'whatsapp.template_sent',
                auditable: $dispatch->incident,
                newValues: [
                    'dispatch_id' => $dispatch->id,
                    'template_key' => $dispatch->template_key,
                    'template_name' => $dispatch->template_name,
                    'template_purpose' => $dispatch->template_purpose,
                    'trigger_source' => $dispatch->trigger_source->value,
                    'interakt_message_id' => $dispatch->interakt_message_id,
                ],
                request: $request,
            );

            if ($configuration->internalNote !== null && $dispatch->triggeredBy !== null) {
                $this->remarkService->createForRemarkable(
                    remarkable: $dispatch->incident,
                    actor: $dispatch->triggeredBy,
                    body: $configuration->internalNote,
                    request: $request,
                );
            }

            return $dispatch->fresh();
        });
    }

    public function markFailed(
        WhatsAppTemplateDispatch $dispatch,
        string $errorMessage,
        ?Request $request = null,
    ): WhatsAppTemplateDispatch {
        $dispatch->update([
            'status' => WhatsAppTemplateDispatchStatus::Failed,
            'error_message' => $errorMessage,
            'dispatched_at' => now(),
        ]);

        $dispatch->loadMissing('incident');

        $this->auditLogService->log(
            userId: $dispatch->triggered_by_user_id,
            event: 'whatsapp.template_failed',
            auditable: $dispatch->incident,
            newValues: [
                'dispatch_id' => $dispatch->id,
                'template_key' => $dispatch->template_key,
                'template_purpose' => $dispatch->template_purpose,
                'trigger_source' => $dispatch->trigger_source->value,
                'error_message' => $errorMessage,
            ],
            request: $request,
        );

        return $dispatch->fresh();
    }
}
