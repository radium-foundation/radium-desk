<?php

namespace App\Services\Bonvoice;

use App\Data\Bonvoice\BonvoiceClickToCallContext;
use App\Data\Bonvoice\BonvoiceClickToCallResult;
use App\Enums\BonvoiceClickToCallFailureCode;
use App\Models\User;
use App\Support\Bonvoice\BonvoiceClickToCallSupportReference;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BonvoiceClickToCallService
{
    public function __construct(
        private readonly BonvoiceAuthentication $authentication,
        private readonly BonvoiceClickToCallMetrics $metrics,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('bonvoice.click_to_call.enabled')
            && filled(config('bonvoice.click_to_call.username'))
            && filled(config('bonvoice.click_to_call.password'))
            && filled(config('bonvoice.click_to_call.did'));
    }

    public function initiateCall(User $agent, BonvoiceClickToCallContext $context): BonvoiceClickToCallResult
    {
        $logContext = [
            'user_id' => $agent->id,
            'incident_id' => $context->incidentId(),
            'order_id' => $context->orderId(),
        ];

        if (! $this->isEnabled()) {
            return $this->fail(
                errorMessage: 'BonVoice Click-to-Call is not configured.',
                failureCode: BonvoiceClickToCallFailureCode::NotConfigured,
                logContext: $logContext,
            );
        }

        $agentPhone = $this->normalizeDialablePhone($agent->bonvoice_extension);

        if ($agentPhone === null) {
            return $this->fail(
                errorMessage: 'Your BonVoice mobile number is not configured. Ask an admin to set it on your user profile.',
                failureCode: BonvoiceClickToCallFailureCode::AgentPhone,
                logContext: $logContext,
            );
        }

        if ($context->customerDialable === '') {
            return $this->fail(
                errorMessage: 'Customer phone number is not valid for calling.',
                failureCode: BonvoiceClickToCallFailureCode::CustomerPhone,
                logContext: $logContext,
            );
        }

        $did = $this->normalizeDid((string) config('bonvoice.click_to_call.did'));

        if ($did === null) {
            return $this->fail(
                errorMessage: 'BonVoice outbound DID is not configured.',
                failureCode: BonvoiceClickToCallFailureCode::NotConfigured,
                logContext: $logContext,
            );
        }

        $eventId = $this->generateEventId();
        $body = $this->buildPayload(
            agentPhone: $agentPhone,
            customerPhone: $context->customerDialable,
            did: $did,
            eventId: $eventId,
            callbackParams: $context->callbackParams($agent, $eventId),
        );

        return $this->dispatchCall(
            body: $body,
            context: [
                'user_id' => $agent->id,
                'incident_id' => $context->incidentId(),
                'order_id' => $context->orderId(),
                'customer_phone' => $context->customerDialable,
                'agent_extension' => $agentPhone,
                'event_id' => $eventId,
                'correlation_id' => $eventId,
            ],
        );
    }

    /**
     * @param  array<string, int|string|null>  $callbackParams
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $agentPhone,
        string $customerPhone,
        string $did,
        string $eventId,
        array $callbackParams,
    ): array {
        return [
            'autocallType' => '3',
            'destination' => $agentPhone,
            'ringStrategy' => 'ringall',
            'legACallerID' => $did,
            'legAChannelID' => '1',
            'legADialAttempts' => '1',
            'legBDestination' => $customerPhone,
            'legBCallerID' => $did,
            'legBChannelID' => '1',
            'legBDialAttempts' => '1',
            'eventID' => $eventId,
            'callBackParams' => $callbackParams,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $context
     */
    private function dispatchCall(array $body, array $context, bool $retryingAfterUnauthorized = false): BonvoiceClickToCallResult
    {
        $startedAt = microtime(true);
        $eventId = isset($context['event_id']) ? (string) $context['event_id'] : null;

        try {
            Log::info('[BonVoice Click-to-Call] Request', [
                ...$context,
                'request' => $body,
                'retrying_after_unauthorized' => $retryingAfterUnauthorized,
            ]);

            $response = $this->httpClient()
                ->withHeaders($this->authentication->headers())
                ->post('/autoDialManagement/autoCallBridging/', $body);
        } catch (ConnectionException $exception) {
            return $this->fail(
                errorMessage: 'Automatic calling failed.',
                failureCode: BonvoiceClickToCallFailureCode::Connection,
                eventId: $eventId,
                correlationId: isset($context['correlation_id']) ? (string) $context['correlation_id'] : $eventId,
                retriable: true,
                logContext: $context,
                executionTimeMs: $this->executionTimeMs($startedAt),
            );
        } catch (RuntimeException $exception) {
            return $this->fail(
                errorMessage: 'Automatic calling failed.',
                failureCode: BonvoiceClickToCallFailureCode::Auth,
                eventId: $eventId,
                correlationId: isset($context['correlation_id']) ? (string) $context['correlation_id'] : $eventId,
                httpStatus: 503,
                retriable: true,
                logContext: $context,
                executionTimeMs: $this->executionTimeMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->fail(
                errorMessage: 'Automatic calling failed.',
                failureCode: BonvoiceClickToCallFailureCode::Connection,
                eventId: $eventId,
                correlationId: isset($context['correlation_id']) ? (string) $context['correlation_id'] : $eventId,
                retriable: true,
                logContext: $context,
                executionTimeMs: $this->executionTimeMs($startedAt),
            );
        }

        if ($response->status() === 401 && ! $retryingAfterUnauthorized) {
            $this->authentication->forgetToken();

            Log::info('[BonVoice Click-to-Call] Unauthorized, retrying once with fresh token', [
                ...$context,
                'execution_time_ms' => $this->executionTimeMs($startedAt),
            ]);

            return $this->dispatchCall($body, $context, retryingAfterUnauthorized: true);
        }

        return $this->resolveDispatchResponse($response, $context, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveDispatchResponse(Response $response, array $context, float $startedAt): BonvoiceClickToCallResult
    {
        $responseBody = $response->json();
        $eventId = isset($context['event_id']) ? (string) $context['event_id'] : null;
        $correlationId = isset($context['correlation_id']) ? (string) $context['correlation_id'] : $eventId;
        $providerDescription = is_array($responseBody)
            ? data_get($responseBody, 'responseDescription')
            : null;
        $providerResponseCode = is_array($responseBody)
            ? (int) data_get($responseBody, 'responseCode', 0)
            : null;
        $executionTimeMs = $this->executionTimeMs($startedAt);
        $logContext = [
            ...$context,
            'correlation_id' => $correlationId,
        ];
        $fail = fn (
            BonvoiceClickToCallFailureCode $failureCode,
            ?int $httpStatus = null,
            bool $retriable = false,
            ?int $responseCode = null,
            ?string $responseDescription = null,
        ) => $this->fail(
            errorMessage: 'Automatic calling failed.',
            failureCode: $failureCode,
            eventId: $eventId,
            correlationId: $correlationId,
            httpStatus: $httpStatus,
            retriable: $retriable,
            logContext: $logContext,
            executionTimeMs: $executionTimeMs,
            providerResponseCode: $responseCode,
            providerResponseDescription: $responseDescription,
        );

        if ($response->status() === 429 || $response->serverError()) {
            return $fail(
                failureCode: BonvoiceClickToCallFailureCode::ProviderHttp,
                httpStatus: $response->status(),
                retriable: true,
                responseCode: $providerResponseCode ?: null,
                responseDescription: is_string($providerDescription) ? $providerDescription : null,
            );
        }

        if ($response->failed()) {
            return $fail(
                failureCode: BonvoiceClickToCallFailureCode::ProviderHttp,
                httpStatus: $response->status(),
                responseCode: $providerResponseCode ?: null,
                responseDescription: is_string($providerDescription) ? $providerDescription : null,
            );
        }

        if (! is_array($responseBody)) {
            return $fail(
                failureCode: BonvoiceClickToCallFailureCode::InvalidResponse,
            );
        }

        if ($providerResponseCode !== 200) {
            return $fail(
                failureCode: BonvoiceClickToCallFailureCode::ProviderResponse,
                httpStatus: $response->status(),
                responseCode: $providerResponseCode,
                responseDescription: is_string($providerDescription) ? $providerDescription : null,
            );
        }

        Log::info('[BonVoice Click-to-Call] Call initiated', [
            ...$logContext,
            'response' => $responseBody,
            'provider_response_description' => is_string($providerDescription) ? $providerDescription : null,
            'http_status' => $response->status(),
            'execution_time_ms' => $executionTimeMs,
        ]);

        $result = BonvoiceClickToCallResult::success(
            eventId: (string) ($context['event_id'] ?? ''),
            message: 'Calling your registered mobile...',
        );
        $this->metrics->recordSuccess($result->eventId);

        return $result;
    }

    private function fail(
        string $errorMessage,
        BonvoiceClickToCallFailureCode $failureCode,
        ?string $eventId = null,
        ?string $correlationId = null,
        ?int $httpStatus = null,
        bool $retriable = false,
        array $logContext = [],
        ?float $executionTimeMs = null,
        ?int $providerResponseCode = null,
        ?string $providerResponseDescription = null,
    ): BonvoiceClickToCallResult {
        $correlationId ??= $eventId ?? $this->generateEventId();
        $referenceId = BonvoiceClickToCallSupportReference::format($eventId, $correlationId);

        BonvoiceClickToCallSupportReference::logFailure(
            referenceId: $referenceId,
            failureCode: $failureCode,
            context: [
                ...$logContext,
                'event_id' => $eventId ?? ($logContext['event_id'] ?? null),
                'correlation_id' => $correlationId,
            ],
            retriable: $retriable,
            executionTimeMs: $executionTimeMs,
            providerResponseCode: $providerResponseCode,
            providerResponseDescription: $providerResponseDescription,
        );

        $result = BonvoiceClickToCallResult::failure(
            errorMessage: $errorMessage,
            failureCode: $failureCode,
            eventId: $eventId,
            correlationId: $correlationId,
            referenceId: $referenceId,
            httpStatus: $httpStatus,
            retriable: $retriable,
        );

        $this->metrics->recordFailure(
            failureCode: $failureCode,
            eventId: $eventId,
            correlationId: $correlationId,
        );

        return $result;
    }

    /**
     * Bonvoice documents eventID as a unique alphanumeric string with ideal length 8-16.
     * UUID (36 chars) exceeds that limit, so we generate 16 uppercase hex characters.
     */
    public function generateEventId(): string
    {
        return strtoupper(bin2hex(random_bytes(8)));
    }

    public function normalizeDialablePhone(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '910')) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return $digits;
    }

    private function normalizeDid(string $did): ?string
    {
        $digits = preg_replace('/\D+/', '', $did) ?? '';

        return $digits !== '' ? $digits : null;
    }

    private function executionTimeMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('bonvoice.click_to_call.base_url'), '/');
    }

    private function httpClient()
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('bonvoice.click_to_call.connect_timeout_seconds', 5))
            ->timeout((int) config('bonvoice.click_to_call.timeout_seconds', 15));
    }
}
