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

        $bodyValues = $this->resolveBodyValues($dispatch, $configuration->bodyParameters);

        if ($bodyValues !== []) {
            $templatePayload['bodyValues'] = $bodyValues;
        }

        $headerValues = $this->resolveHeaderValues($dispatch, $configuration->headerParameters);

        if ($headerValues !== []) {
            $templatePayload['headerValues'] = $headerValues;
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

    /**
     * @param  list<array<string, mixed>>  $configuredBodyParameters
     * @return list<string>
     */
    /**
     * @param  list<array<string, mixed>>  $configuredHeaderParameters
     * @return list<string>
     */
    private function resolveHeaderValues(WhatsAppTemplateDispatch $dispatch, array $configuredHeaderParameters): array
    {
        $context = $dispatch->context ?? [];
        $contextHeaderValues = $context['header_values'] ?? null;

        if (is_array($contextHeaderValues) && $contextHeaderValues !== []) {
            return array_values(array_map(
                fn (mixed $value): string => trim((string) $value),
                $contextHeaderValues,
            ));
        }

        if ($configuredHeaderParameters === []) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $configuredHeaderParameters,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $configuredBodyParameters
     * @return list<string>
     */
    private function resolveBodyValues(WhatsAppTemplateDispatch $dispatch, array $configuredBodyParameters): array
    {
        $context = $dispatch->context ?? [];
        $contextBodyValues = $context['body_values'] ?? null;

        if (is_array($contextBodyValues) && $contextBodyValues !== []) {
            return array_values(array_map(
                fn (mixed $value): string => trim((string) $value),
                $contextBodyValues,
            ));
        }

        if ($configuredBodyParameters === []) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $configuredBodyParameters,
        ));
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
