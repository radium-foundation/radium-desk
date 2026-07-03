<?php

namespace App\Services\Interakt;

use App\Data\InteraktSendResult;
use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Models\InteraktMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InteraktService
{
    public function __construct(
        private readonly InteraktAuthentication $authentication,
    ) {}
    public function sendTextMessage(
        string $countryCode,
        string $phoneNumber,
        string $text,
        ?string $callbackData = null,
    ): InteraktSendResult {
        $body = [
            'countryCode' => $countryCode,
            'phoneNumber' => $phoneNumber,
            'type' => 'Text',
            'data' => [
                'message' => $text,
            ],
        ];

        if ($callbackData !== null && $callbackData !== '') {
            $body['callbackData'] = $callbackData;
        }

        return $this->sendMessage($body, $countryCode, $phoneNumber, 'text', $text);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    public function sendTemplateMessage(
        string $countryCode,
        string $phoneNumber,
        array $template,
        ?string $callbackData = null,
    ): InteraktSendResult {
        $body = [
            'countryCode' => $countryCode,
            'phoneNumber' => $phoneNumber,
            'type' => 'Template',
            'template' => $template,
        ];

        if ($callbackData !== null && $callbackData !== '') {
            $body['callbackData'] = $callbackData;
        }

        $templateName = is_string($template['name'] ?? null) ? $template['name'] : null;

        return $this->sendMessage($body, $countryCode, $phoneNumber, 'template', null, $templateName);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sendMessage(
        array $body,
        string $countryCode,
        string $phoneNumber,
        string $messageType,
        ?string $text = null,
        ?string $templateName = null,
    ): InteraktSendResult {
        if (! filled(config('interakt.api_key'))) {
            return InteraktSendResult::failure('Interakt API key is not configured.');
        }

        try {
            Log::debug('[Interakt] Outbound request', [
                'url' => rtrim((string) config('interakt.base_url'), '/').'/v1/public/message/',
                'headers' => $this->authentication->redactHeadersForLogging($this->authentication->headers()),
            ]);

            $response = $this->httpClient()
                ->post('/v1/public/message/', $body);

            if ($response->status() === 429 || $response->serverError()) {
                return InteraktSendResult::failure(
                    errorMessage: $this->resolveErrorMessage($response->json(), 'Interakt API request failed.'),
                    httpStatus: $response->status(),
                    retriable: true,
                );
            }

            if ($response->failed()) {
                return InteraktSendResult::failure(
                    errorMessage: $this->resolveErrorMessage($response->json(), 'Interakt API rejected the request.'),
                    httpStatus: $response->status(),
                    retriable: false,
                );
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return InteraktSendResult::failure('Interakt API returned a non-JSON response.');
            }

            $messageId = $this->extractMessageId($payload);

            if ($messageId === null) {
                return InteraktSendResult::failure('Interakt API response is missing message id.');
            }

            $storedPhone = app(InteraktCustomerMatcher::class)
                ->resolveStoredPhone($countryCode, $phoneNumber);

            InteraktMessage::query()->updateOrCreate(
                ['message_id' => $messageId],
                [
                    'customer_phone' => $storedPhone ?? $phoneNumber,
                    'direction' => InteraktMessageDirection::Outgoing,
                    'message_type' => $messageType,
                    'text' => $text,
                    'template_name' => $templateName,
                    'delivery_status' => InteraktDeliveryStatus::Sent,
                    'sent_at' => now(),
                    'payload' => $payload,
                ],
            );

            return InteraktSendResult::success($messageId);
        } catch (ConnectionException $exception) {
            Log::warning('[Interakt] Connection failed while sending message.', [
                'message' => $exception->getMessage(),
            ]);

            return InteraktSendResult::failure($exception->getMessage(), retriable: true);
        } catch (RequestException $exception) {
            Log::warning('[Interakt] Request failed while sending message.', [
                'message' => $exception->getMessage(),
            ]);

            return InteraktSendResult::failure($exception->getMessage(), retriable: true);
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function verifyApiResponse(?array $payload): bool
    {
        return $this->extractMessageId($payload ?? []) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractMessageId(array $payload): ?string
    {
        foreach (['id', 'message_id', 'messageId'] as $key) {
            $value = data_get($payload, $key) ?? data_get($payload, "data.{$key}") ?? data_get($payload, "message.{$key}");

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function resolveErrorMessage(?array $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        foreach (['message', 'error', 'detail'] as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl((string) config('interakt.base_url'))
            ->withHeaders($this->authentication->headers())
            ->connectTimeout((int) config('interakt.connect_timeout_seconds'))
            ->timeout((int) config('interakt.timeout_seconds'))
            ->retry(
                times: max(0, (int) config('interakt.max_retries')),
                sleepMilliseconds: (int) config('interakt.retry_delay_ms'),
                when: fn (\Throwable $exception, \Illuminate\Http\Client\PendingRequest $request): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response?->serverError() === true),
                throw: false,
            );
    }
}
