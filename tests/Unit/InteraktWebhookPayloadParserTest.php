<?php

namespace Tests\Unit;

use App\Enums\InteraktDeliveryStatus;
use App\Services\Interakt\InteraktWebhookPayloadParser;
use Illuminate\Support\Carbon;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class InteraktWebhookPayloadParserTest extends TestCase
{
    use InteractsWithInteraktWebhooks;

    private InteraktWebhookPayloadParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = app(InteraktWebhookPayloadParser::class);
    }

    public function test_parses_official_channel_phone_number(): void
    {
        $payload = $this->officialIncomingMessagePayload(channelPhoneNumber: '919876543210');

        $this->assertSame('919876543210', $this->parser->channelPhoneNumber($payload));
        $this->assertNull($this->parser->countryCode($payload));
        $this->assertNull($this->parser->phoneNumber($payload));
    }

    public function test_parses_legacy_country_code_and_phone_number(): void
    {
        $payload = $this->legacyIncomingMessagePayload();

        $this->assertSame('+91', $this->parser->countryCode($payload));
        $this->assertSame('9876543210', $this->parser->phoneNumber($payload));
    }

    public function test_prefers_message_lifecycle_timestamps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $payload = $this->officialApiReadPayload();

        $this->assertSame(
            '2022-06-03T05:43:33.133000',
            $this->parser->receivedAtUtc($payload)?->format('Y-m-d\TH:i:s.u'),
        );
        $this->assertSame(
            '2022-06-03T05:43:33.848000',
            $this->parser->deliveredAtUtc($payload)?->format('Y-m-d\TH:i:s.u'),
        );
        $this->assertSame(
            '2022-06-03T05:43:34.257000',
            $this->parser->seenAtUtc($payload)?->format('Y-m-d\TH:i:s.u'),
        );
        $this->assertSame(
            '2022-06-03T05:43:34.257000',
            $this->parser->statusTimestamp($payload)?->format('Y-m-d\TH:i:s.u'),
        );
    }

    public function test_extracts_template_metadata_from_raw_template(): void
    {
        $payload = $this->officialApiSentPayload();

        $this->assertSame('Repair Started', $this->parser->templateName($payload));
        $this->assertSame('en', $this->parser->templateLanguage($payload));

        $metadata = $this->parser->templateMetadata($payload);
        $this->assertSame('Repair Started', $metadata['name']);
        $this->assertSame('en', $metadata['language']);
        $this->assertSame('Hello {{1}}', $metadata['body']);
    }

    public function test_extracts_callback_and_failure_metadata(): void
    {
        $payload = $this->officialApiFailedPayload();

        $this->assertSame('service-case:RD-WA-1', $this->parser->callbackData($this->officialApiSentPayload()));
        $this->assertSame('Recipient is not a valid WhatsApp user', $this->parser->channelFailureReason($payload));
        $this->assertSame('1013', $this->parser->channelErrorCode($payload));
    }

    public function test_maps_api_and_campaign_delivery_statuses(): void
    {
        $this->assertSame(
            InteraktDeliveryStatus::Sent,
            $this->parser->deliveryStatus($this->officialApiSentPayload()),
        );
        $this->assertSame(
            InteraktDeliveryStatus::Delivered,
            $this->parser->deliveryStatus($this->officialApiDeliveredPayload()),
        );
        $this->assertSame(
            InteraktDeliveryStatus::Read,
            $this->parser->deliveryStatus($this->officialApiReadPayload()),
        );
        $this->assertSame(
            InteraktDeliveryStatus::Failed,
            $this->parser->deliveryStatus($this->officialApiFailedPayload()),
        );
        $this->assertSame(
            InteraktDeliveryStatus::Read,
            $this->parser->deliveryStatus($this->officialCampaignReadPayload()),
        );
        $this->assertSame(
            InteraktDeliveryStatus::Failed,
            $this->parser->deliveryStatus($this->officialCampaignFailedPayload()),
        );
    }
}
