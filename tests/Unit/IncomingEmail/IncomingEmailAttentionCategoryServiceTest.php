<?php

namespace Tests\Unit\IncomingEmail;

use App\Enums\IncomingEmailAttentionCategory;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailAttentionCategoryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncomingEmailAttentionCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'inbound_email.priority_phrases' => [],
        ]);

        $this->seed(RolePermissionSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_aggregate_counts_uses_distinct_customer_email_lookup(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        for ($index = 0; $index < 150; $index++) {
            Order::query()->create([
                'order_id' => 'RD-OOM-'.$index,
                'serial_number' => 'SN-OOM-'.$index,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'customer_name' => 'Repeat Customer',
                'customer_phone' => '9876501234',
                'customer_email' => 'repeat@example.com',
                'status' => 'active',
                'created_by' => $creator->id,
            ]);
        }

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'known-repeat-customer',
            'from_email' => 'repeat@example.com',
            'subject' => 'Order follow up',
            'preview' => 'Need help',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'received_at' => now(),
        ]);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'sales@radiumbox.com',
            'channel' => 'sales',
            'provider' => 'fixture',
            'provider_message_id' => 'unknown-lead',
            'from_email' => 'lead@example.com',
            'subject' => 'Pricing',
            'preview' => 'Pricing',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::PossibleSalesLead,
            'received_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $counts = app(IncomingEmailAttentionCategoryService::class)->aggregateCounts();

        $orderLookupQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'customer_email'));

        DB::disableQueryLog();

        $this->assertSame([
            'sales' => 1,
            'orders' => 1,
            'priority' => 0,
        ], $counts);

        $this->assertTrue(
            $orderLookupQueries->contains(
                fn (string $query): bool => str_contains(strtolower($query), 'distinct')
            ),
            'Known-customer email lookup must use DISTINCT to avoid materializing one row per order.',
        );
    }

    public function test_soft_deleted_orders_are_excluded_from_known_customer_email_lookup(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deletedOrder = Order::query()->create([
            'order_id' => 'RD-DELETED',
            'serial_number' => 'SN-DELETED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Deleted Customer',
            'customer_phone' => '9876501234',
            'customer_email' => 'deleted@example.com',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
        $deletedOrder->delete();

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'deleted-customer',
            'from_email' => 'deleted@example.com',
            'subject' => 'Help',
            'preview' => 'Help',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'received_at' => now(),
        ]);

        $counts = app(IncomingEmailAttentionCategoryService::class)->aggregateCounts();

        $this->assertSame(1, $counts[IncomingEmailAttentionCategory::Sales->value]);
        $this->assertSame(0, $counts[IncomingEmailAttentionCategory::Orders->value]);
    }

    public function test_known_customer_email_without_prior_classification_is_orders(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-KNOWN',
            'serial_number' => 'SN-KNOWN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Known Customer',
            'customer_phone' => '9876501234',
            'customer_email' => 'known@example.com',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'known-customer',
            'from_email' => 'known@example.com',
            'subject' => 'Follow up',
            'preview' => 'Follow up',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'received_at' => now(),
        ]);

        $counts = app(IncomingEmailAttentionCategoryService::class)->aggregateCounts();

        $this->assertSame(1, $counts[IncomingEmailAttentionCategory::Orders->value]);
    }
}
