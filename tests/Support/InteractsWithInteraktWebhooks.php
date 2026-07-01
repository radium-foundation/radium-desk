<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

trait InteractsWithInteraktWebhooks
{
    /**
     * Official Interakt webhook secret used in tests.
     */
    protected function interaktWebhookSecret(): string
    {
        return (string) config('interakt.webhook_secret');
    }

    /**
     * @return array<string, mixed>
     */
    protected function officialRawTemplate(
        string $name = 'test_template_lp',
        string $displayName = 'Repair Started',
        string $language = 'en',
        string $body = 'Hello {{1}}',
    ): string {
        return json_encode([
            'name' => $name,
            'display_name' => $displayName,
            'language' => $language,
            'body' => $body,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     * @param  array<string, mixed>  $customerOverrides
     * @return array<string, mixed>
     */
    protected function officialTemplateStatusPayload(
        string $type,
        string $messageId = 'dfc668a2-c06c-4e9a-a4fd-7b65bc1fdc84',
        string $channelPhoneNumber = '919876543210',
        array $messageOverrides = [],
        array $customerOverrides = [],
    ): array {
        return [
            'version' => '1.0',
            'timestamp' => '2022-06-03T05:43:33.237499',
            'type' => $type,
            'data' => [
                'customer' => array_merge([
                    'id' => '52918eb3-bd00-4331-a51d-c4dcffee48d6',
                    'channel_phone_number' => $channelPhoneNumber,
                    'traits' => [
                        'name' => 'Test Customer',
                    ],
                ], $customerOverrides),
                'message' => array_merge([
                    'id' => $messageId,
                    'chat_message_type' => 'PublicApiMessage',
                    'channel_failure_reason' => null,
                    'message_status' => 'Sent',
                    'received_at_utc' => '2022-06-03T05:43:33.133000',
                    'delivered_at_utc' => null,
                    'seen_at_utc' => null,
                    'campaign_id' => null,
                    'is_template_message' => true,
                    'raw_template' => $this->officialRawTemplate(),
                    'channel_error_code' => null,
                    'message_content_type' => 'Template',
                    'media_url' => null,
                    'message' => '[{"type":"body","parameters":[{"type":"text","text":"Customer"}]}]',
                    'meta_data' => [
                        'source' => 'PublicInterakt',
                        'source_data' => [
                            'callback_data' => 'service-case:RD-WA-1',
                        ],
                    ],
                ], $messageOverrides),
            ],
        ];
    }

    protected function officialApiSentPayload(
        string $messageId = 'dfc668a2-c06c-4e9a-a4fd-7b65bc1fdc84',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_api_sent',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'message_status' => 'Sent',
                'received_at_utc' => '2022-06-03T05:43:33.133000',
                'delivered_at_utc' => null,
                'seen_at_utc' => null,
            ],
        );
    }

    protected function officialApiDeliveredPayload(
        string $messageId = 'dfc668a2-c06c-4e9a-a4fd-7b65bc1fdc84',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_api_delivered',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'message_status' => 'Delivered',
                'received_at_utc' => '2022-06-03T05:43:33.133000',
                'delivered_at_utc' => '2022-06-03T05:43:33.848000',
                'seen_at_utc' => null,
            ],
        );
    }

    protected function officialApiReadPayload(
        string $messageId = 'dfc668a2-c06c-4e9a-a4fd-7b65bc1fdc84',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_api_read',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'message_status' => 'Read',
                'received_at_utc' => '2022-06-03T05:43:33.133000',
                'delivered_at_utc' => '2022-06-03T05:43:33.848000',
                'seen_at_utc' => '2022-06-03T05:43:34.257000',
            ],
        );
    }

    protected function officialApiFailedPayload(
        string $messageId = '80b4b1f1-dc39-46dc-a133-bf09a12c3d4e',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_api_failed',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'message_status' => 'Failed',
                'channel_failure_reason' => 'Recipient is not a valid WhatsApp user',
                'channel_error_code' => '1013',
                'received_at_utc' => '2022-06-03T05:56:10.502000',
                'delivered_at_utc' => null,
                'seen_at_utc' => null,
            ],
        );
    }

    protected function officialCampaignSentPayload(
        string $messageId = 'campaign-msg-001',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_campaign_sent',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'chat_message_type' => 'CampaignMessage',
                'message_status' => 'Sent',
                'received_at_utc' => '2022-06-03T06:00:00.000000',
            ],
        );
    }

    protected function officialCampaignDeliveredPayload(
        string $messageId = 'campaign-msg-001',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_campaign_delivered',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'chat_message_type' => 'CampaignMessage',
                'message_status' => 'Delivered',
                'received_at_utc' => '2022-06-03T06:00:00.000000',
                'delivered_at_utc' => '2022-06-03T06:00:05.000000',
            ],
        );
    }

    protected function officialCampaignReadPayload(
        string $messageId = 'campaign-msg-001',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_campaign_read',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'chat_message_type' => 'CampaignMessage',
                'message_status' => 'Read',
                'received_at_utc' => '2022-06-03T06:00:00.000000',
                'delivered_at_utc' => '2022-06-03T06:00:05.000000',
                'seen_at_utc' => '2022-06-03T06:00:10.000000',
            ],
        );
    }

    protected function officialCampaignFailedPayload(
        string $messageId = 'campaign-msg-failed-001',
        string $channelPhoneNumber = '919876543210',
    ): array {
        return $this->officialTemplateStatusPayload(
            type: 'message_campaign_failed',
            messageId: $messageId,
            channelPhoneNumber: $channelPhoneNumber,
            messageOverrides: [
                'chat_message_type' => 'CampaignMessage',
                'message_status' => 'Failed',
                'channel_failure_reason' => 'Campaign delivery failed',
                'channel_error_code' => '2001',
                'received_at_utc' => '2022-06-03T06:05:00.000000',
            ],
        );
    }

    protected function officialIncomingMessagePayload(
        string $messageId = '60076f05-da52-4dd1-b813-36223c1eded7',
        string $channelPhoneNumber = '919876543210',
        string $text = 'When will my device be ready?',
    ): array {
        return [
            'version' => '1.0',
            'timestamp' => '2022-06-03T05:57:57.496889',
            'type' => 'message_received',
            'data' => [
                'customer' => [
                    'id' => '52918eb3-bd00-4331-a51d-c4dcffee48d6',
                    'channel_phone_number' => $channelPhoneNumber,
                    'traits' => [
                        'name' => 'Test Customer',
                    ],
                ],
                'message' => [
                    'id' => $messageId,
                    'chat_message_type' => 'CustomerMessage',
                    'channel_failure_reason' => null,
                    'message_status' => 'Sent',
                    'received_at_utc' => '2022-06-03T05:57:57.359000',
                    'delivered_at_utc' => null,
                    'seen_at_utc' => null,
                    'campaign_id' => null,
                    'is_template_message' => false,
                    'raw_template' => null,
                    'channel_error_code' => null,
                    'message_content_type' => 'Text',
                    'media_url' => null,
                    'message' => $text,
                    'meta_data' => [],
                ],
            ],
        ];
    }

    /**
     * Legacy simplified payload retained for backward-compatibility coverage.
     *
     * @return array<string, mixed>
     */
    protected function legacyIncomingMessagePayload(
        string $messageId = 'msg-legacy-in-001',
        string $phoneNumber = '9876543210',
        string $countryCode = '+91',
        string $text = 'Legacy payload message',
    ): array {
        return [
            'type' => 'message_received',
            'timestamp' => '2026-07-01T10:00:00+05:30',
            'data' => [
                'customer' => [
                    'country_code' => $countryCode,
                    'phone_number' => $phoneNumber,
                ],
                'message' => [
                    'id' => $messageId,
                    'chat_message_type' => 'CustomerMessage',
                    'message' => $text,
                    'message_content_type' => 'Text',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedInteraktWebhook(array $payload, ?string $secret = null): TestResponse
    {
        /** @var TestCase $this */
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $secret ??= $this->interaktWebhookSecret();
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return $this->call(
            'POST',
            '/api/webhooks/interakt',
            [],
            [],
            [],
            [
                'HTTP_Interakt-Signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody,
        );
    }

    /** @deprecated Use officialIncomingMessagePayload() */
    protected function incomingMessagePayload(
        string $messageId = '60076f05-da52-4dd1-b813-36223c1eded7',
        string $phoneNumber = '9876543210',
        string $countryCode = '+91',
        string $text = 'When will my device be ready?',
    ): array {
        return $this->officialIncomingMessagePayload(
            messageId: $messageId,
            channelPhoneNumber: '91'.$phoneNumber,
            text: $text,
        );
    }

    /** @deprecated Use officialApiDeliveredPayload() */
    protected function templateDeliveredPayload(
        string $messageId = 'dfc668a2-c06c-4e9a-a4fd-7b65bc1fdc84',
        string $phoneNumber = '9876543210',
        string $countryCode = '+91',
        string $templateName = 'Repair Started',
    ): array {
        return $this->officialApiDeliveredPayload(
            messageId: $messageId,
            channelPhoneNumber: '91'.$phoneNumber,
        );
    }

    /** @deprecated Use officialApiReadPayload() */
    protected function templateReadPayload(
        string $messageId = 'dfc668a2-c06c-4e9a-a4fd-7b65bc1fdc84',
        string $phoneNumber = '9876543210',
        string $countryCode = '+91',
    ): array {
        return $this->officialApiReadPayload(
            messageId: $messageId,
            channelPhoneNumber: '91'.$phoneNumber,
        );
    }
}
