<?php

namespace Tests\Unit;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\WhatsAppConversationStatus;
use App\Models\InteraktMessage;
use App\Models\WhatsAppCommunicationSummary;
use App\Services\Interakt\InteraktDeepLinkService;
use App\Services\Interakt\WhatsAppCommunicationSummaryBuilder;
use App\Services\Interakt\WhatsAppCommunicationSummaryStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppCommunicationSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_builder_aggregates_messages_and_derives_status(): void
    {
        InteraktMessage::query()->create([
            'message_id' => 'msg-1',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming,
            'message_type' => 'text',
            'text' => 'Any update?',
            'sent_at' => now()->subHour(),
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-2',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'delivery_status' => InteraktDeliveryStatus::Delivered,
            'sent_at' => now()->subMinutes(10),
        ]);

        $summary = app(WhatsAppCommunicationSummaryBuilder::class)->buildForPhone('9876543210');

        $this->assertNotNull($summary);
        $this->assertSame(2, $summary->messages_exchanged_count);
        $this->assertSame(WhatsAppConversationStatus::WaitingForCustomer, $summary->conversation_status);
        $this->assertSame('template', $summary->last_sender);
        $this->assertSame('Repair Started', $summary->last_template_name);
        $this->assertSame('msg-2', $summary->last_message_id);
    }

    public function test_store_updates_existing_summary_on_refresh(): void
    {
        InteraktMessage::query()->create([
            'message_id' => 'msg-initial',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'delivery_status' => InteraktDeliveryStatus::Sent,
            'sent_at' => now()->subHour(),
        ]);

        app(WhatsAppCommunicationSummaryStore::class)->refreshForPhone('9876543210');

        InteraktMessage::query()->create([
            'message_id' => 'msg-followup',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming,
            'message_type' => 'text',
            'text' => 'Thanks',
            'sent_at' => now()->subMinute(),
        ]);

        app(WhatsAppCommunicationSummaryStore::class)->refreshForPhone('9876543210');

        $summary = WhatsAppCommunicationSummary::query()->where('customer_phone', '9876543210')->first();

        $this->assertNotNull($summary);
        $this->assertSame(2, $summary->messages_exchanged_count);
        $this->assertSame(WhatsAppConversationStatus::WaitingForAgent, $summary->conversation_status);
        $this->assertSame('msg-followup', $summary->last_message_id);
    }

    public function test_deep_link_prefers_conversation_template_when_configured(): void
    {
        config([
            'interakt.conversation_url_template' => 'https://app.interakt.ai/inbox/{customer_id}',
            'interakt.customer_profile_url_template' => 'https://app.interakt.ai/contacts?search={phone}',
        ]);

        $summary = WhatsAppCommunicationSummary::query()->create([
            'customer_phone' => '9876543210',
            'interakt_customer_id' => 'cust-123',
            'conversation_status' => WhatsAppConversationStatus::WaitingForCustomer,
            'messages_exchanged_count' => 1,
            'last_activity_at' => now(),
            'last_communication_at' => now(),
        ]);

        $url = app(InteraktDeepLinkService::class)->conversationUrl($summary);

        $this->assertSame('https://app.interakt.ai/inbox/cust-123', $url);
    }

    public function test_deep_link_falls_back_to_customer_profile_template(): void
    {
        config([
            'interakt.conversation_url_template' => '',
            'interakt.customer_profile_url_template' => 'https://app.interakt.ai/contacts?search={phone}',
        ]);

        $summary = WhatsAppCommunicationSummary::query()->create([
            'customer_phone' => '9876543210',
            'interakt_customer_id' => 'cust-123',
            'conversation_status' => WhatsAppConversationStatus::WaitingForCustomer,
            'messages_exchanged_count' => 1,
            'last_activity_at' => now(),
            'last_communication_at' => now(),
        ]);

        $url = app(InteraktDeepLinkService::class)->conversationUrl($summary);

        $this->assertSame('https://app.interakt.ai/contacts?search=9876543210', $url);
    }
}
