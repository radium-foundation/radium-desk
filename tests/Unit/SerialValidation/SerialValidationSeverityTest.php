<?php

namespace Tests\Unit\SerialValidation;

use App\Data\SerialValidationResult;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SerialValidationSeverity;
use App\Enums\SerialValidationStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialValidationService;
use App\Services\SerialValidation\Validators\Fm220SerialValidator;
use App\Services\SerialValidation\Validators\Marc11SerialValidator;
use App\Services\SerialValidation\Validators\Mfs110SerialValidator;
use App\Services\SerialValidation\Validators\MsoE3SerialValidator;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAutomationStatusService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialValidationSeverityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_production_example_rd3442121_fm220_model_26_passes(): void
    {
        $result = $this->validateProductionOrder('RD3442121', 'M260779805', 'Access FM220 L1');

        $this->assertSame(SerialValidationSeverity::Pass, $result->severity);
        $this->assertTrue($result->isValid());
    }

    public function test_production_example_rd3442035_mfs_product_label_fails(): void
    {
        $result = $this->validateProductionOrder('RD3442035', '54SAXXC5514586', 'MFS110');

        $this->assertSame(SerialValidationSeverity::Fail, $result->severity);
        $this->assertTrue($result->isInvalid());
        $this->assertStringContainsString('product labels', (string) $result->reason);
    }

    public function test_production_example_rd3442024_fm220_wrong_length_fails(): void
    {
        $result = $this->validateProductionOrder('RD3442024', 'TC067262100185', 'Access FM220 L1');

        $this->assertSame(SerialValidationSeverity::Fail, $result->severity);
        $this->assertTrue($result->isInvalid());
    }

    public function test_fm220_b47_nine_character_pattern_warns(): void
    {
        $result = app(Fm220SerialValidator::class)->validate('B47C11929');

        $this->assertSame(SerialValidationSeverity::Warning, $result->severity);
        $this->assertSame(SerialValidationStatus::Warning, $result->status);
        $this->assertTrue($result->allowsWorkflow());
    }

    public function test_fm220_n01_nine_character_pattern_warns(): void
    {
        $result = app(Fm220SerialValidator::class)->validate('N01907786');

        $this->assertSame(SerialValidationSeverity::Warning, $result->severity);
        $this->assertTrue($result->allowsWorkflow());
    }

    public function test_marc11_2503102880_warns_instead_of_failing(): void
    {
        $result = app(Marc11SerialValidator::class)->validate('2503102880');

        $this->assertSame(SerialValidationSeverity::Warning, $result->severity);
        $this->assertSame(SerialValidationStatus::Warning, $result->status);
        $this->assertStringContainsString('25 prefix', (string) $result->reason);
    }

    public function test_mso_e3_prefix_20_warns(): void
    {
        $result = app(MsoE3SerialValidator::class)->validate('2029I023123');

        $this->assertSame(SerialValidationSeverity::Warning, $result->severity);
        $this->assertTrue($result->allowsWorkflow());
    }

    public function test_mfs110_preserves_hard_failures_for_kamal_and_voltage_and_part_numbers(): void
    {
        $validator = app(Mfs110SerialValidator::class);

        foreach (['KAMAL', '5VDC/0.5A', 'P/N:FPSPL1141XX', '54SAXXC3089378'] as $serial) {
            $result = $validator->validate($serial);

            $this->assertSame(
                SerialValidationSeverity::Fail,
                $result->severity,
                'Expected hard failure for '.$serial,
            );
        }
    }

    public function test_warning_allows_workflow_but_fail_blocks_assignment_eligibility(): void
    {
        $warningOrder = Order::query()->create([
            'order_id' => 'RD-WARN-FLOW',
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'created_by' => User::factory()->create()->id,
        ]);

        $failOrder = Order::query()->create([
            'order_id' => 'RD-FAIL-FLOW',
            'serial_number' => '54SAXXC5514586',
            'device_model' => 'MFS110',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'created_by' => User::factory()->create()->id,
        ]);

        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);

        $this->assertTrue($eligibility->passesValidationForOrder($warningOrder));
        $this->assertFalse($eligibility->passesValidationForOrder($failOrder));
    }

    public function test_fail_routes_to_attention_queue_and_warning_routes_to_action_required(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $failIncident = $this->createAssignedIncident($agent, 'RD-QUEUE-FAIL', '54SAXXC5514586', 'MFS110');
        $warningIncident = $this->createAssignedIncident($agent, 'RD-QUEUE-WARN', 'B47C11929', 'Access FM220 L1');

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($failIncident->order->id);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($warningIncident->order->id);

        $automationStatus = app(ServiceCaseAutomationStatusService::class);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(
            ServiceCaseAutomationStatus::ValidationFailed,
            $automationStatus->statusFor($failIncident->fresh(['order', 'assignee'])),
        );
        $this->assertSame(
            ServiceCaseAutomationStatus::ValidationWarning,
            $automationStatus->statusFor($warningIncident->fresh(['order', 'assignee'])),
        );

        $this->assertSame(
            OperationQueue::Attention,
            $classifier->classify($failIncident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments'])),
        );
        $this->assertSame(
            OperationQueue::ActionRequired,
            $classifier->classify($warningIncident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments'])),
        );
    }

    public function test_assert_valid_for_order_allows_warning_serial_entry(): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-ASSERT-WARN',
            'serial_number' => null,
            'device_model' => 'Access FM220 L1',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);

        $result = app(SerialValidationService::class)->assertValidForOrder('B47C11929', $order);

        $this->assertSame(SerialValidationSeverity::Warning, $result->severity);
    }

    public function test_serial_validation_result_factories_expose_severity(): void
    {
        $valid = SerialValidationResult::valid('7881953', 'MFS 110');
        $warning = SerialValidationResult::warning('B47C11929', 'FM 220', 'Needs review.');
        $invalid = SerialValidationResult::invalid('bad', 'MFS 110', 'Invalid serial.');

        $this->assertSame(SerialValidationSeverity::Pass, $valid->severity);
        $this->assertSame(SerialValidationSeverity::Warning, $warning->severity);
        $this->assertSame(SerialValidationSeverity::Fail, $invalid->severity);
    }

    private function validateProductionOrder(string $orderId, string $serial, string $deviceModel): SerialValidationResult
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serial,
            'device_model' => $deviceModel,
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'created_by' => User::factory()->create()->id,
        ]);

        return app(SerialValidationService::class)->validateForOrder($serial, $order);
    }

    private function createAssignedIncident(User $assignee, string $orderId, string $serial, string $deviceModel): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serial,
            'device_model' => $deviceModel,
            'status' => 'active',
            'created_by' => $assignee->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Severity queue test',
            'description' => 'Severity queue test.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $assignee->id,
        ]);
    }
}
