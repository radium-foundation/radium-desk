<?php

namespace Tests\Unit;

use App\Support\Customer360\Customer360HealthCardPresenter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360HealthCardPresenterTest extends TestCase
{
    public function test_present_builds_metrics_from_existing_health_card_and_summary(): void
    {
        $whatsappAt = Carbon::parse('2026-07-10 14:30:00');
        $emailAt = Carbon::parse('2026-07-09 10:00:00');

        $viewModel = app(Customer360HealthCardPresenter::class)->present(
            [
                'active_service_cases' => 1,
                'last_whatsapp' => [
                    'status' => 'sent',
                    'last_sent_at' => $whatsappAt,
                ],
                'last_email' => [
                    'status' => 'sent',
                    'last_sent_at' => $emailAt,
                ],
                'last_call' => null,
                'repeat_contact' => null,
            ],
            [
                'total_orders' => 2,
                'open_cases' => 1,
                'closed_cases' => 1,
            ],
            null,
        );

        $this->assertSame('attention', $viewModel['status']['status']);
        $this->assertSame('Attention', $viewModel['status']['label']);
        $this->assertSame(2, $viewModel['total_orders']);
        $this->assertSame(1, $viewModel['active_service_cases']);
        $this->assertSame(1, $viewModel['completed_service_cases']);
        $this->assertSame('WhatsApp', $viewModel['preferred_channel']);
        $this->assertSame('WhatsApp', $viewModel['last_contact']['label']);
        $this->assertTrue($whatsappAt->equalTo($viewModel['last_contact']['occurred_at']));
    }

    public function test_present_marks_high_urgency_repeat_contact_as_critical(): void
    {
        $viewModel = app(Customer360HealthCardPresenter::class)->present(
            [
                'active_service_cases' => 0,
                'last_whatsapp' => ['status' => 'not_sent'],
                'last_email' => ['status' => 'not_sent'],
                'last_call' => null,
                'repeat_contact' => [
                    'high_urgency' => true,
                    'total_today' => 3,
                ],
            ],
            [
                'total_orders' => 1,
                'open_cases' => 0,
                'closed_cases' => 0,
            ],
            null,
        );

        $this->assertSame('critical', $viewModel['status']['status']);
    }
}
