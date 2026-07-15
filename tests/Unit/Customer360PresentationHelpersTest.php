<?php

namespace Tests\Unit;

use App\Support\Customer360\Customer360AgentNamePresenter;
use App\Support\Customer360\Customer360CommunicationActionDisplayName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Customer360PresentationHelpersTest extends TestCase
{
    #[DataProvider('agentFirstNameProvider')]
    public function test_agent_name_presenter_returns_first_name_only(?string $fullName, ?string $firstName, ?string $expected): void
    {
        $this->assertSame($expected, Customer360AgentNamePresenter::displayFirstName($fullName, $firstName));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string}>
     */
    public static function agentFirstNameProvider(): array
    {
        return [
            'full name' => ['Gaurav Kumar', null, 'Gaurav'],
            'two part name' => ['Jayram Patel', null, 'Jayram'],
            'single name' => ['Shipra', null, 'Shipra'],
            'prefers first_name column' => ['Gaurav Kumar', 'Gaurav', 'Gaurav'],
            'empty' => [null, null, null],
        ];
    }

    #[DataProvider('communicationActionDisplayNameProvider')]
    public function test_communication_action_display_name(string $key, string $name, string $expected): void
    {
        $this->assertSame($expected, Customer360CommunicationActionDisplayName::for($key, $name));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function communicationActionDisplayNameProvider(): array
    {
        return [
            'review request' => ['review_request', 'Review Request', 'Send Review Request'],
            'refund confirmation' => ['refund_confirmation', 'Refund Confirmation', 'Send Refund Confirmation'],
            'driver guide' => ['driver_installation_guide', 'Driver Installation Guide', 'Send Driver Installation Guide'],
            'buy product unchanged' => ['buy_product', 'Buy Product', 'Buy Product'],
            'buy rd service unchanged' => ['buy_rd_service', 'Buy RD Service', 'Buy RD Service'],
        ];
    }
}
