<?php

namespace App\Services\Bonvoice;

use Illuminate\Http\Request;

class BonvoiceWebhookAuthVerifier
{
    public const ERROR_MISSING_ACCOUNT_ID = 'BonVoice webhook payload is missing AccountID.';

    public const ERROR_INVALID_ACCOUNT_ID = 'BonVoice webhook AccountID does not match configured account.';

    public const ERROR_MISSING_AUTHORIZATION = 'BonVoice webhook authorization header is missing.';

    public const ERROR_INVALID_AUTHORIZATION = 'Invalid BonVoice webhook authorization';

    public function __construct(
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
    ) {}

    public function shouldVerify(): bool
    {
        return $this->shouldVerifyAccountId() || $this->shouldVerifyBearer();
    }

    public function shouldVerifyAccountId(): bool
    {
        return (bool) config('bonvoice.verify_webhook_auth');
    }

    public function shouldVerifyBearer(): bool
    {
        return (bool) config('bonvoice.require_bearer')
            || (bool) config('bonvoice.verify_signature');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(Request $request, array $payload): BonvoiceWebhookAuthResult
    {
        if ($this->shouldVerifyAccountId()) {
            $accountResult = $this->verifyAccountId($payload);

            if (! $accountResult->isValid()) {
                return $accountResult;
            }
        }

        if ($this->shouldVerifyBearer()) {
            $bearerResult = $this->verifyBearer($request);

            if (! $bearerResult->isValid()) {
                return $bearerResult;
            }
        }

        return BonvoiceWebhookAuthResult::valid();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verifyAccountId(array $payload): BonvoiceWebhookAuthResult
    {
        $configuredAccountId = (string) config('bonvoice.account_id');

        if ($configuredAccountId === '') {
            return BonvoiceWebhookAuthResult::valid();
        }

        $payloadAccountId = $this->payloadParser->accountId($payload);

        if ($payloadAccountId === null) {
            return BonvoiceWebhookAuthResult::invalid(
                error: self::ERROR_MISSING_ACCOUNT_ID,
                statusCode: 400,
            );
        }

        if (! hash_equals($configuredAccountId, $payloadAccountId)) {
            return BonvoiceWebhookAuthResult::invalid(
                error: self::ERROR_INVALID_ACCOUNT_ID,
                statusCode: 401,
            );
        }

        return BonvoiceWebhookAuthResult::valid();
    }

    private function verifyBearer(Request $request): BonvoiceWebhookAuthResult
    {
        $authorization = $this->headerValue($request, 'Authorization');

        if ($authorization === null) {
            return BonvoiceWebhookAuthResult::invalid(
                error: self::ERROR_MISSING_AUTHORIZATION,
                statusCode: 400,
            );
        }

        $token = $this->extractBearerToken($authorization);
        $configuredToken = (string) config('bonvoice.webhook_token');

        if ($token === null || $configuredToken === '' || ! hash_equals($configuredToken, $token)) {
            return BonvoiceWebhookAuthResult::invalid(
                error: self::ERROR_INVALID_AUTHORIZATION,
                statusCode: 401,
            );
        }

        return BonvoiceWebhookAuthResult::valid();
    }

    private function extractBearerToken(string $authorization): ?string
    {
        if (! str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token === '' ? null : $token;
    }

    private function headerValue(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
