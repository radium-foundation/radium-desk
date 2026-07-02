<?php

namespace App\Services\Interakt;

use App\Data\WhatsAppTemplateDispatchResult;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\Outbox\OutboxProcessorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppTemplateDispatcher
{
    public function __construct(
        private readonly WhatsAppTemplateConfigurationResolver $configurationResolver,
        private readonly InteraktOutboundOutboxWriter $outboxWriter,
        private readonly OutboxProcessorService $outboxProcessor,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        WhatsAppTemplate $template,
        Incident $incident,
        ?User $actor,
        WhatsAppTemplateTriggerSource $triggerSource,
        array $context = [],
        ?Request $request = null,
    ): WhatsAppTemplateDispatchResult {
        $incident->loadMissing('order');

        if ($incident->order === null) {
            return WhatsAppTemplateDispatchResult::failure(null, 'Service case is not linked to an order.');
        }

        $configuration = $this->configurationResolver->resolve($template);

        $dispatch = DB::transaction(function () use (
            $template,
            $incident,
            $actor,
            $triggerSource,
            $context,
            $configuration,
        ): WhatsAppTemplateDispatch {
            return WhatsAppTemplateDispatch::query()->create([
                'incident_id' => $incident->id,
                'order_id' => $incident->order_id,
                'triggered_by_user_id' => $actor?->id,
                'template_key' => $template->value,
                'template_name' => $configuration->name,
                'template_display_name' => $configuration->displayName,
                'template_purpose' => $configuration->purpose,
                'trigger_source' => $triggerSource,
                'status' => WhatsAppTemplateDispatchStatus::Pending,
                'customer_phone' => $incident->order->customer_phone,
                'context' => $context === [] ? null : $context,
            ]);
        });

        $this->outboxWriter->writeSendJob($dispatch->id);

        try {
            $this->outboxProcessor->processAggregate(
                InteraktOutboundOutboxWriter::AGGREGATE_TYPE,
                $dispatch->id,
            );
        } catch (\Throwable $exception) {
            Log::error('whatsapp.template.dispatch.exception', [
                'dispatch_id' => $dispatch->id,
                'incident_id' => $incident->id,
                'template' => $template->value,
                'exception' => $exception->getMessage(),
            ]);

            $dispatch->refresh();

            if ($dispatch->status === WhatsAppTemplateDispatchStatus::Failed) {
                return WhatsAppTemplateDispatchResult::failure(
                    $dispatch,
                    $dispatch->error_message ?? $exception->getMessage(),
                );
            }

            return WhatsAppTemplateDispatchResult::failure(
                $dispatch,
                'WhatsApp template dispatch failed: '.$exception->getMessage(),
            );
        }

        $dispatch->refresh();

        return match ($dispatch->status) {
            WhatsAppTemplateDispatchStatus::Sent => WhatsAppTemplateDispatchResult::success(
                $dispatch,
                'WhatsApp template sent successfully.',
            ),
            WhatsAppTemplateDispatchStatus::Failed => WhatsAppTemplateDispatchResult::failure(
                $dispatch,
                $dispatch->error_message ?? 'WhatsApp template dispatch failed.',
            ),
            WhatsAppTemplateDispatchStatus::Pending => WhatsAppTemplateDispatchResult::failure(
                $dispatch,
                'WhatsApp template dispatch is still pending. The outbox will retry automatically.',
            ),
            default => WhatsAppTemplateDispatchResult::failure(
                $dispatch,
                sprintf(
                    'WhatsApp template dispatch returned unexpected status: %s.',
                    $dispatch->status->value,
                ),
            ),
        };
    }
}
