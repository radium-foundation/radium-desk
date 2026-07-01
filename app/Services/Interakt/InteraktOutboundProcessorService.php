<?php

namespace App\Services\Interakt;

use App\Data\WhatsAppTemplateConfiguration;
use App\Models\WhatsAppTemplateDispatch;
use Illuminate\Http\Request;
use RuntimeException;

class InteraktOutboundProcessorService
{
    public function __construct(
        private readonly InteraktService $interaktService,
        private readonly WhatsAppTemplateConfigurationResolver $configurationResolver,
        private readonly WhatsAppTemplateDispatchCompletionService $completionService,
        private readonly InteraktPhoneResolver $phoneResolver,
    ) {}

    public function processDispatch(int $dispatchId, ?Request $request = null): WhatsAppTemplateDispatch
    {
        $dispatch = WhatsAppTemplateDispatch::query()->with(['incident.order', 'triggeredBy'])->find($dispatchId);

        if ($dispatch === null) {
            throw new RuntimeException('WhatsApp template dispatch not found: '.$dispatchId);
        }

        if ($dispatch->status !== \App\Enums\WhatsAppTemplateDispatchStatus::Pending) {
            return $dispatch;
        }

        $configuration = $this->configurationResolver->resolve(
            \App\Enums\WhatsAppTemplate::from($dispatch->template_key),
        );

        $phone = $this->phoneResolver->resolveForStoredPhone($dispatch->customer_phone);

        if ($phone === null) {
            return $this->completionService->markFailed(
                $dispatch,
                'Customer phone number is not available.',
                $request,
            );
        }

        $templatePayload = [
            'name' => $configuration->name,
            'languageCode' => $configuration->languageCode,
        ];

        if ($configuration->bodyParameters !== []) {
            $templatePayload['bodyValues'] = $configuration->bodyParameters;
        }

        if ($configuration->headerParameters !== []) {
            $templatePayload['headerValues'] = $configuration->headerParameters;
        }

        $result = $this->interaktService->sendTemplateMessage(
            countryCode: $phone['country_code'],
            phoneNumber: $phone['phone_number'],
            template: $templatePayload,
            callbackData: $this->callbackData($dispatch),
        );

        if ($result->success) {
            return $this->completionService->markSent($dispatch, $result, $configuration, $request);
        }

        return $this->completionService->markFailed(
            $dispatch,
            $result->errorMessage ?? 'Interakt rejected the template request.',
            $request,
        );
    }

    private function callbackData(WhatsAppTemplateDispatch $dispatch): string
    {
        $dispatch->loadMissing('incident');

        return sprintf(
            'incident:%d;template:%s;dispatch:%d',
            $dispatch->incident_id,
            $dispatch->template_key,
            $dispatch->id,
        );
    }
}
