<?php

namespace App\Services\Interakt;

use App\Data\InteraktSendResult;
use App\Data\WhatsAppTemplateConfiguration;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\AuditLogService;
use App\Services\RemarkService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

class WhatsAppTemplateDispatchCompletionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly RemarkService $remarkService,
        private readonly \App\Services\Operations\TeamMemberActivityService $activityService,
    ) {}

    public function markSent(
        WhatsAppTemplateDispatch $dispatch,
        InteraktSendResult $result,
        WhatsAppTemplateConfiguration $configuration,
        ?Request $request = null,
    ): WhatsAppTemplateDispatch {
        DB::transaction(function () use ($dispatch, $result): void {
            $dispatch->update([
                'status' => WhatsAppTemplateDispatchStatus::Sent,
                'interakt_message_id' => $result->messageId,
                'error_message' => null,
                'dispatched_at' => now(),
            ]);
        });

        $this->runAfterDatabaseCommit(function () use ($dispatch, $configuration, $request): void {
            try {
                $this->recordPostSendSideEffects($dispatch, $configuration, $request);
            } catch (QueryException|PDOException $exception) {
                if (! $this->isOperationalDatabaseException($exception)) {
                    throw $exception;
                }

                Log::error('whatsapp.template.mark_sent.side_effects_failed', [
                    'dispatch_id' => $dispatch->id,
                    'incident_id' => $dispatch->incident_id,
                    'exception' => $exception::class,
                    'sqlstate' => $this->sqlStateFrom($exception),
                    'message' => $exception->getMessage(),
                ]);
            }
        });

        return $dispatch->fresh();
    }

    private function recordPostSendSideEffects(
        WhatsAppTemplateDispatch $dispatch,
        WhatsAppTemplateConfiguration $configuration,
        ?Request $request,
    ): void {
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

        if ($dispatch->triggeredBy !== null) {
            $this->activityService->recordCustomerCommunication($dispatch->triggeredBy);
        }
    }

    private function isOperationalDatabaseException(QueryException|PDOException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        // MySQL / MariaDB: 1213 deadlock, 1205 lock wait timeout, 2006 server gone away.
        if (in_array($driverCode, [1213, 1205, 2006], true)) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, '1213')
            || str_contains($message, 'Deadlock')
            || str_contains($message, '1205')
            || str_contains($message, 'Lock wait timeout')
            || str_contains($message, 'MySQL server has gone away')
            || str_contains($message, '2006');
    }

    private function sqlStateFrom(QueryException|PDOException $exception): ?string
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return is_string($sqlState) && $sqlState !== '' ? $sqlState : null;
    }

    private function runAfterDatabaseCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
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
