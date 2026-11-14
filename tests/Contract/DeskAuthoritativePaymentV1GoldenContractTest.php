<?php

namespace Tests\Contract;

use App\Data\RadiumBox\DeskAuthoritativePaymentEvent;
use App\Services\RadiumBox\DeskAuthoritativePaymentEventSigner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeskAuthoritativePaymentV1GoldenContractTest extends TestCase
{
    public function test_desk_transmit_bytes_match_cross_repo_golden_fixture(): void
    {
        $golden = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/desk-authoritative-payment-v1-golden.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $event = new DeskAuthoritativePaymentEvent(
            eventId: 'desk-payment:order:73007:6998469575',
            idempotencyKey: 'desk-payment:radiumbox_com:RBP2138:6998469575',
            businessOrderId: 'RBP2138',
            deskOrderId: 73007,
            provider: 'cashfree',
            paymentStatus: 'paid',
            currency: 'INR',
            amount: '2499.00',
            cashfreePaymentId: '6998469575',
            cashfreeOrderId: null,
            bankReference: null,
            verifiedAt: Carbon::parse('2026-11-14T07:37:12+00:00'),
            verificationSource: 'cashfree_webhook',
        );

        $body = $event->transmitJson(false);
        $this->assertSame($golden['transmit_body'], $body);
        $this->assertSame(
            $golden['signature'],
            app(DeskAuthoritativePaymentEventSigner::class)->signBody($body, $golden['meta']['signing_secret']),
        );
        $this->assertSame('desk_authoritative_payment_v1', $golden['meta']['contract_version']);
    }

    public function test_reencoded_body_invalidates_golden_signature(): void
    {
        $golden = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/desk-authoritative-payment-v1-golden.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $tampered = $golden['transmit_body'].' ';
        $sig = app(DeskAuthoritativePaymentEventSigner::class)->signBody($tampered, $golden['meta']['signing_secret']);
        $this->assertNotSame($golden['signature'], $sig);
    }
}