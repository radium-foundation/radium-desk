<?php

namespace Tests\Unit\Bonvoice;

use App\Enums\BonvoiceClickToCallFailureCode;
use App\Support\Bonvoice\BonvoiceClickToCallSupportReference;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BonvoiceClickToCallSupportReferenceTest extends TestCase
{
    public function test_format_derives_reference_from_event_id(): void
    {
        $referenceId = BonvoiceClickToCallSupportReference::format(
            eventId: 'A1B2C3D4E5F60718',
            correlationId: 'FFFFFFFFFFFFFFFF',
        );

        $this->assertSame('BV-A1B2C3D4', $referenceId);
    }

    public function test_format_falls_back_to_correlation_id(): void
    {
        $referenceId = BonvoiceClickToCallSupportReference::format(
            correlationId: '1234567890ABCDEF',
        );

        $this->assertSame('BV-12345678', $referenceId);
    }

    public function test_log_failure_emits_single_structured_context(): void
    {
        Log::spy();

        BonvoiceClickToCallSupportReference::logFailure(
            referenceId: 'BV-DEADBEEF',
            failureCode: BonvoiceClickToCallFailureCode::ProviderHttp,
            context: [
                'event_id' => 'DEADBEEFCAFEBABE',
                'correlation_id' => 'DEADBEEFCAFEBABE',
                'incident_id' => 42,
                'order_id' => 7,
                'user_id' => 3,
            ],
            retriable: true,
            executionTimeMs: 12.5,
            providerResponseCode: 500,
            providerResponseDescription: 'Internal provider error',
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[BonVoice Click-to-Call] Failure'
                    && ($context['reference_id'] ?? null) === 'BV-DEADBEEF'
                    && ($context['failure_code'] ?? null) === 'provider_http'
                    && ($context['event_id'] ?? null) === 'DEADBEEFCAFEBABE'
                    && ($context['incident_id'] ?? null) === 42
                    && ($context['order_id'] ?? null) === 7
                    && ($context['user_id'] ?? null) === 3
                    && ($context['execution_time_ms'] ?? null) === 12.5
                    && ($context['provider_response_code'] ?? null) === 500
                    && ($context['provider_response_description'] ?? null) === 'Internal provider error'
                    && ($context['retriable'] ?? null) === true;
            })
            ->once();
    }
}
