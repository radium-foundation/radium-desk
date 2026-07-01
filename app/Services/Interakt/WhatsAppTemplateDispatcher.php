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
use RuntimeException;

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
            $this->outboxProcessor->process(limit: 1);
        } catch (\Throwable $exception) {
            $dispatch->refresh();

            if ($dispatch->status === WhatsAppTemplateDispatchStatus::Failed) {
                return WhatsAppTemplateDispatchResult::failure(
                    $dispatch,
                    $dispatch->error_message ?? $exception->getMessage(),
                );
            }

            throw $exception;
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
            default => throw new RuntimeException('WhatsApp template dispatch did not complete.'),
        };
    }
}
