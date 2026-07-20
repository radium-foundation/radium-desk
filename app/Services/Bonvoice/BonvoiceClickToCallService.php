<?php

namespace App\Services\Bonvoice;

use App\Data\Bonvoice\BonvoiceClickToCallContext;
use App\Data\Bonvoice\BonvoiceClickToCallResult;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BonvoiceClickToCallService
{
    public function __construct(
        private readonly BonvoiceAuthentication $authentication,
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
        if (! $this->isEnabled()) {
            return BonvoiceClickToCallResult::failure('BonVoice Click-to-Call is not configured.');
        }

        $agentPhone = $this->normalizeDialablePhone($agent->bonvoice_extension);

        if ($agentPhone === null) {
            return BonvoiceClickToCallResult::failure(
                'Your BonVoice mobile number is not configured. Ask an admin to set it on your user profile.',
            );
        }

        if ($context->customerDialable === '') {
            return BonvoiceClickToCallResult::failure('Customer phone number is not valid for calling.');
        }

        $did = $this->normalizeDid((string) config('bonvoice.click_to_call.did'));

        if ($did === null) {
            return BonvoiceClickToCallResult::failure('BonVoice outbound DID is not configured.');
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
            Log::warning('[BonVoice Click-to-Call] Connection failed', [
                ...$context,
                'message' => $exception->getMessage(),
                'execution_time_ms' => $this->executionTimeMs($startedAt),
            ]);

            return BonvoiceClickToCallResult::failure(
                errorMessage: 'Automatic calling failed.',
                retriable: true,
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
        $logContext = [
            ...$context,
            'response' => is_array($responseBody) ? $responseBody : $response->body(),
            'http_status' => $response->status(),
            'execution_time_ms' => $this->executionTimeMs($startedAt),
        ];

        if ($response->status() === 429 || $response->serverError()) {
            Log::warning('[BonVoice Click-to-Call] Retriable API failure', $logContext);

            return BonvoiceClickToCallResult::failure(
                errorMessage: 'Automatic calling failed.',
                httpStatus: $response->status(),
                retriable: true,
            );
        }

        if ($response->failed()) {
            Log::warning('[BonVoice Click-to-Call] API rejected request', $logContext);

            return BonvoiceClickToCallResult::failure(
                errorMessage: 'Automatic calling failed.',
                httpStatus: $response->status(),
            );
        }

        if (! is_array($responseBody)) {
            Log::warning('[BonVoice Click-to-Call] Invalid response body', $logContext);

            return BonvoiceClickToCallResult::failure('Automatic calling failed.');
        }

        $responseCode = (int) data_get($responseBody, 'responseCode', 0);

        if ($responseCode !== 200) {
            Log::warning('[BonVoice Click-to-Call] API returned non-success response', $logContext);

            return BonvoiceClickToCallResult::failure(
                errorMessage: 'Automatic calling failed.',
                httpStatus: $response->status(),
            );
        }

        Log::info('[BonVoice Click-to-Call] Call initiated', $logContext);

        return BonvoiceClickToCallResult::success(
            eventId: (string) ($context['event_id'] ?? ''),
            message: 'Calling your registered mobile...',
        );
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
