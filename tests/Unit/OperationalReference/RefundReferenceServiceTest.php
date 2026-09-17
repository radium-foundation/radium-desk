<?php

namespace Tests\Unit\OperationalReference;

use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\ReferenceSequence;
use App\Models\User;
use App\Services\RefundReferenceService;
use App\Support\OperationalReference\OperationalReferenceParser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private RefundReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(RefundReferenceService::class);
    }

    public function test_first_new_refund_reference_is_ref_67315(): void
    {
        $this->assertSame('REF-67315', $this->service->generate());
        $this->assertSame(67315, ReferenceSequence::query()->find(ReferenceSequence::REFUND_OPERATIONAL)?->current_value);
    }

    public function test_existing_ref_67315_advances_to_ref_67316(): void
    {
        $this->seedRefundReference('REF-67315');
        $this->syncSequenceTo(67315);

        $this->assertSame('REF-67316', $this->service->generate());
    }

    public function test_existing_ref_67320_advances_to_ref_67321(): void
    {
        $this->seedRefundReference('REF-67320');
        $this->syncSequenceTo(67320);

        $this->assertSame('REF-67321', $this->service->generate());
    }

    public function test_historical_ref_2026_000314_does_not_break_new_format(): void
    {
        $this->seedRefundReference('REF-2026-000314');
        $this->syncSequenceTo(67314);

        $next = $this->service->generate();

        $this->assertSame('REF-67315', $next);
        $this->assertDoesNotMatchRegularExpression('/^REF-\d{4}-\d{6}$/', $next);
    }

    public function test_mixed_old_and_new_references_produce_correct_next_value(): void
    {
        $this->seedRefundReference('REF-2026-000001');
        $this->seedRefundReference('REF-2026-000314');
        $this->seedRefundReference('REF-67318');
        $this->syncSequenceTo(67318);

        $this->assertSame('REF-67319', $this->service->generate());
    }

    public function test_historical_refund_references_remain_unchanged(): void
    {
        $historical = $this->seedRefundReference('REF-2026-000099');

        $this->service->generate();

        $this->assertSame('REF-2026-000099', $historical->fresh()->reference_no);
    }

    public function test_generate_produces_unique_sequential_references(): void
    {
        $references = [];

        for ($index = 0; $index < 20; $index++) {
            $references[] = $this->service->generate();
        }

        $this->assertSame(20, count(array_unique($references)));
        $this->assertSame('REF-67334', end($references));
        $this->assertDoesNotMatchRegularExpression('/^REF-\d{4}-\d{6}$/', end($references));
    }

    public function test_generate_does_not_scan_refund_requests_table(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->generate();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower($query))
            ->implode("\n");

        $this->assertStringNotContainsString('from "refund_requests"', $queries);
        $this->assertStringNotContainsString('from `refund_requests`', $queries);
        $this->assertStringContainsString('reference_sequences', $queries);
    }

    public function test_peek_next_reports_upcoming_value_without_consuming(): void
    {
        $this->syncSequenceTo(67319);

        $this->assertSame(67320, $this->service->peekNext());
        $this->assertSame(67319, ReferenceSequence::query()->find(ReferenceSequence::REFUND_OPERATIONAL)?->current_value);
    }

    private function seedRefundReference(string $referenceNo): RefundRequest
    {
        $user = User::factory()->create();

        return RefundRequest::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'RD-'.uniqid(),
                'serial_number' => 'SN-'.uniqid(),
                'product_name' => 'Device',
                'device_model' => 'Model',
                'status' => 'active',
                'created_by' => $user->id,
            ])->id,
            'reference_no' => $referenceNo,
            'amount' => 100,
            'reason' => 'Operational reference regression seed.',
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);
    }

    private function syncSequenceTo(int $value): void
    {
        ReferenceSequence::query()
            ->where('name', ReferenceSequence::REFUND_OPERATIONAL)
            ->update(['current_value' => $value]);
    }
}
