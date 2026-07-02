<?php

namespace Tests\Unit\Notifications;

use App\Data\NotificationDispatchResult;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use Tests\TestCase;

class NotificationDeliverySummaryFormatterTest extends TestCase
{
    private NotificationDeliverySummaryFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = app(NotificationDeliverySummaryFormatter::class);
    }

    public function test_formats_operator_result_with_delivered_labels_and_suffix(): void
    {
        $summary = $this->formatter->formatOperatorResult(new NotificationDispatchResult(
            success: true,
            results: [
                NotificationResult::success(
                    channel: NotificationChannelType::Email,
                    message: 'Email notification sent successfully.',
                    metadata: ['status' => 'sent'],
                ),
                NotificationResult::failure(
                    channel: NotificationChannelType::WhatsApp,
                    message: 'Invalid Interakt token.',
                    metadata: ['status' => 'failed'],
                ),
            ],
        ), 'Waiting state started.');

        $this->assertSame(
            "Notification sent with warnings\n✓ Email delivered\n✗ WhatsApp\nInvalid Interakt token.\nWaiting state started.",
            $summary,
        );
    }

    public function test_formats_all_successful_channels(): void
    {
        $summary = $this->formatter->format(new NotificationDispatchResult(
            success: true,
            results: [
                NotificationResult::success(
                    channel: NotificationChannelType::WhatsApp,
                    message: 'WhatsApp template sent successfully.',
                    metadata: ['status' => 'sent'],
                ),
                NotificationResult::success(
                    channel: NotificationChannelType::Email,
                    message: 'Email notification sent successfully.',
                    metadata: ['status' => 'sent'],
                ),
            ],
        ));

        $this->assertSame(
            "Notification sent\n✓ WhatsApp\n✓ Email",
            $summary,
        );
    }

    public function test_formats_partial_success_with_failures_and_skipped_channels(): void
    {
        $summary = $this->formatter->format(new NotificationDispatchResult(
            success: true,
            results: [
                NotificationResult::success(
                    channel: NotificationChannelType::WhatsApp,
                    message: 'WhatsApp template sent successfully.',
                    metadata: ['status' => 'sent'],
                ),
                NotificationResult::failure(
                    channel: NotificationChannelType::Email,
                    message: 'Customer email address is not available.',
                    metadata: ['status' => 'missing_customer_email'],
                ),
                NotificationResult::success(
                    channel: NotificationChannelType::Desktop,
                    message: 'Not Yet Configured',
                    metadata: ['status' => 'not_yet_configured'],
                ),
                NotificationResult::success(
                    channel: NotificationChannelType::Telegram,
                    message: 'Not Yet Configured',
                    metadata: ['status' => 'not_yet_configured'],
                ),
            ],
        ));

        $this->assertSame(
            "Notification sent with warnings\n✓ WhatsApp\n✗ Email: Customer email address is not available.\n⏭ Desktop (Not configured)\n⏭ Telegram (Not configured)",
            $summary,
        );
    }

    public function test_formats_complete_failure_with_detailed_channel_errors(): void
    {
        $summary = $this->formatter->failureMessage(new NotificationDispatchResult(
            success: false,
            results: [
                NotificationResult::failure(
                    channel: NotificationChannelType::WhatsApp,
                    message: 'WhatsApp dispatch failed.',
                    retryable: true,
                    metadata: ['status' => 'failed'],
                ),
                NotificationResult::failure(
                    channel: NotificationChannelType::Email,
                    message: 'Customer email address is not available.',
                    metadata: ['status' => 'missing_customer_email'],
                ),
            ],
        ));

        $this->assertSame(
            "Notification failed\n✗ WhatsApp: WhatsApp dispatch failed.\n✗ Email: Customer email address is not available.",
            $summary,
        );
    }

    public function test_failure_message_falls_back_when_no_channels_ran(): void
    {
        $summary = $this->formatter->failureMessage(new NotificationDispatchResult(
            success: false,
            results: [],
            message: 'No notification channels are available.',
        ));

        $this->assertSame('No notification channels are available.', $summary);
    }
}
