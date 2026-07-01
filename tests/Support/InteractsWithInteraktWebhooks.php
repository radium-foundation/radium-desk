<?php

namespace Tests\Support;

trait InteractsWithInteraktWebhooks
{
    /**
     * @return array<string, mixed>
     */
    protected function incomingMessagePayload(
        string $messageId = 'msg-in-001',
        string $phoneNumber = '9876543210',
        string $countryCode = '+91',
        string $text = 'When will my device be ready?',
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
     * @return array<string, mixed>
     */
    protected function templateDeliveredPayload(
        string $messageId = 'msg-out-001',
        string $phoneNumber = '9876543210',
        string $countryCode = '+91',
        string $templateName = 'Repair Started',
    ): array {
        return [
            'type' => 'message_api_delivered',
            'timestamp' => '2026-07-01T10:05:00+05:30',
            'data' => [
                'customer' => [
                    'country_code' => $countryCode,
                    'phone_number' => $phoneNumber,
                ],
                'message' => [
                    'id' => $messageId,
                    'chat_message_type' => 'AgentMessage',
                    'template_name' => $templateName,
                    'message_content_type' => 'Template',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateReadPayload(
        string $messageId = 'msg-out-001',
        string $phoneNumber = '9876543210',
        string $countryCode = '+91',
    ): array {
        return [
            'type' => 'message_api_read',
            'timestamp' => '2026-07-01T10:10:00+05:30',
            'data' => [
                'customer' => [
                    'country_code' => $countryCode,
                    'phone_number' => $phoneNumber,
                ],
                'message' => [
                    'id' => $messageId,
                    'chat_message_type' => 'AgentMessage',
                ],
            ],
        ];
    }
}
