<?php

namespace Tests\Unit\SerialValidation;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SerialInsightConfidence;
use App\Enums\SerialInsightStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialInsightService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SerialInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_detects_known_invalid_fm220_serial_pattern(): void
    {
        $order = $this->createOrder('RD-INSIGHT-FM220', 'TC067262100185', 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('FM220', $insight->explanation);
        $this->assertStringContainsString('back-panel serial photo', $insight->explanation);
        $this->assertStringContainsString('WhatsApp', (string) $insight->suggestedAction);
        $this->assertDoesNotMatchRegularExpression('/[\x{0900}-\x{097F}]/u', (string) $insight->suggestedAction);
    }

    public function test_detects_product_code_submitted_as_serial(): void
    {
        $order = $this->createOrder('RD-INSIGHT-MFS', '54SAXXC5514586', 'MFS 110');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('product code', $insight->explanation);
    }

    public function test_detects_radiumbox_identity_mismatch_when_synced_serial_fails_validation(): void
    {
        $order = $this->createOrder('RD-INSIGHT-RB', 'INVALID-SERIAL', 'Access FM220 L1');
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('RadiumBox data', $insight->explanation);
    }

    public function test_valid_serial_returns_high_confidence_valid_status(): void
    {
        $order = $this->createOrder('RD-INSIGHT-VALID', 'M260779805', 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertFalse($insight->isActionable());
    }

    public function test_missing_serial_returns_actionable_missing_insight(): void
    {
        $order = $this->createOrder('RD-INSIGHT-MISSING', null, 'FM220');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Missing, $insight->status);
        $this->assertTrue($insight->isActionable());
        $this->assertStringContainsString('WhatsApp', (string) $insight->suggestedAction);
        $this->assertStringContainsString('serial number', (string) $insight->suggestedAction);
    }

    #[DataProvider('msoE3CorrectSamples')]
    public function test_mso_e3_correct_samples_match_expected_format(string $serial): void
    {
        $order = $this->createOrder('RD-MSO-VALID-'.substr($serial, -4), $serial, 'MSO E3');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('MSO E3', $insight->explanation);
    }

    public static function msoE3CorrectSamples(): array
    {
        return [
            ['2402I013577'],
            ['2441I041803'],
            ['2506I022251'],
            ['2507I005871'],
            ['2351I002764'],
        ];
    }

    #[DataProvider('msoE3WrongSamples')]
    public function test_mso_e3_wrong_samples_are_suspicious(string $serial, ?string $expectedFragment = null): void
    {
        $order = $this->createOrder('RD-MSO-WRONG-'.md5($serial), $serial, 'MSO E3');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString($expectedFragment ?? 'MSO E3', $insight->explanation);
        $this->assertDoesNotMatchRegularExpression('/[\x{0900}-\x{097F}]/u', $insight->explanation);
    }

    public static function msoE3WrongSamples(): array
    {
        return [
            'O vs I confusion' => ['171O1367737', 'O vs I confusion'],
            'model name entered' => ['MSO1300 E3-L1 RD', 'product code'],
            'model shorthand' => ['MSO1300 e3', 'product code'],
            'rejected prefix' => ['2208I013400', 'MSOE3'],
            'unrelated prefix' => ['ESIAP6641', 'back-side label photo'],
            'marketplace code' => ['MPH-SE005C', 'product code'],
            'wrong batch style' => ['IIMPROUB2035', 'back-side label photo'],
        ];
    }

    #[DataProvider('fm220CorrectSamples')]
    public function test_fm220_correct_samples_are_valid(string $serial): void
    {
        $order = $this->createOrder('RD-FM220-VALID-'.substr($serial, -4), $serial, 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
    }

    public static function fm220CorrectSamples(): array
    {
        return [
            ['M240327686'],
            ['M240365655'],
            ['M240261927'],
            ['M240367367'],
        ];
    }

    #[DataProvider('fm220WrongSamples')]
    public function test_fm220_wrong_samples_are_suspicious(string $serial): void
    {
        $order = $this->createOrder('RD-FM220-WRONG-'.md5($serial), $serial, 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertTrue($insight->isActionable());
        $this->assertContains($insight->status, [SerialInsightStatus::Suspicious, SerialInsightStatus::Warning]);
        $this->assertStringContainsString('FM220', strtoupper(str_replace(' ', '', $insight->explanation)));
    }

    public static function fm220WrongSamples(): array
    {
        return [
            ['B47966880'],
            ['B47C70263'],
            ['N00106486'],
            ['M2506300030'],
            ['X002AQXA2p'],
            ['9009370'],
        ];
    }

    public function test_fm220_model_name_entered_as_serial_is_suspicious(): void
    {
        $order = $this->createOrder('RD-FM220-MODEL-NAME', 'FM220U L1', 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('product code', $insight->explanation);
        $this->assertStringContainsString('back-panel serial photo', $insight->explanation);
    }

    #[DataProvider('mfs110CorrectSamples')]
    public function test_mfs110_correct_samples_are_valid(string $serial): void
    {
        $order = $this->createOrder('RD-MFS110-VALID-'.substr($serial, -4), $serial, 'MFS 110');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
    }

    public static function mfs110CorrectSamples(): array
    {
        return [
            ['6419897'],
            ['7438383'],
            ['8910298'],
            ['10452948'],
        ];
    }

    #[DataProvider('mfs110WrongSamples')]
    public function test_mfs110_wrong_samples_are_suspicious(string $serial): void
    {
        $order = $this->createOrder('RD-MFS110-WRONG-'.md5($serial), $serial, 'MFS 110');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
    }

    public static function mfs110WrongSamples(): array
    {
        return [
            ['MFS110'],
            ['MANTRA MFS110'],
            ['MFS110-FPSPL1141XX'],
            ['127.0.0.1:11100'],
            ['079-49068000'],
        ];
    }

    #[DataProvider('mis100CorrectSamples')]
    public function test_mis100_correct_samples_are_valid(string $serial): void
    {
        $order = $this->createOrder('RD-MIS100-VALID-'.substr($serial, -4), $serial, 'MIS 100');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
    }

    public static function mis100CorrectSamples(): array
    {
        return [
            ['6300791'],
            ['3673434'],
            ['5969551'],
            ['8850830'],
        ];
    }

    #[DataProvider('mis100WrongSamples')]
    public function test_mis100_wrong_samples_need_verification(string $serial): void
    {
        $order = $this->createOrder('RD-MIS100-WRONG-'.md5($serial), $serial, 'MIS 100');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertTrue($insight->isActionable());
        $this->assertContains($insight->status, [SerialInsightStatus::Suspicious, SerialInsightStatus::Warning]);
        $this->assertStringContainsString('MIS', $insight->explanation);
        $this->assertStringContainsString('back-side photo', $insight->explanation);
    }

    public static function mis100WrongSamples(): array
    {
        return [
            ['8011332'],
            ['6389437'],
            ['1CD8E86E46E0'],
        ];
    }

    public function test_mis100_model_name_entered_as_serial_is_suspicious(): void
    {
        $order = $this->createOrder('RD-MIS100-MODEL-NAME', 'MIS100V2', 'MIS 100');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('product code', $insight->explanation);
        $this->assertStringContainsString('back-side photo', $insight->explanation);
    }

    public function test_same_serial_behaves_differently_by_product_model(): void
    {
        $serial = '6300791';

        $mfsOrder = $this->createOrder('RD-CROSS-MFS', $serial, 'MFS 110');
        $misOrder = $this->createOrder('RD-CROSS-MIS', $serial, 'MIS 100');

        $mfsInsight = app(SerialInsightService::class)->analyze($mfsOrder);
        $misInsight = app(SerialInsightService::class)->analyze($misOrder);

        $this->assertSame(SerialInsightStatus::Warning, $mfsInsight->status);
        $this->assertStringContainsString('MIS 100', $mfsInsight->explanation);

        $this->assertSame(SerialInsightStatus::Valid, $misInsight->status);
        $this->assertSame(SerialInsightConfidence::High, $misInsight->confidence);
    }

    public function test_model_name_entered_as_serial_is_high_confidence_suspicious(): void
    {
        foreach (['MFS110', 'MIS100V2', 'FM220U L1', 'MSO1300 e3'] as $serial) {
            $deviceModel = match (true) {
                str_contains($serial, 'MFS') => 'MFS 110',
                str_contains($serial, 'MIS') => 'MIS 100',
                str_contains($serial, 'FM220') => 'Access FM220 L1',
                default => 'MSO E3',
            };

            $order = $this->createOrder('RD-MODEL-NAME-'.md5($serial), $serial, $deviceModel);
            $insight = app(SerialInsightService::class)->analyze($order);

            $this->assertSame(
                SerialInsightStatus::Suspicious,
                $insight->status,
                "Expected suspicious insight for {$serial} on {$deviceModel}",
            );
            $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        }
    }

    public function test_mantra_mis100_model_with_mis100v2_serial_returns_model_name_guidance(): void
    {
        $order = $this->createOrder('RD-MANTRA-MIS-MODEL', 'MIS100V2', 'Mantra MIS100');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('product code', $insight->explanation);
        $this->assertStringContainsString('back-side photo', $insight->explanation);
        $this->assertNotSame('No IRA validation rules are configured for this product.', $insight->technicalReason);
    }

    public function test_mantra_mis100_model_with_valid_serial_passes(): void
    {
        $order = $this->createOrder('RD-MANTRA-MIS-VALID', '6300791', 'Mantra MIS100');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertFalse($insight->isActionable());
    }

    public function test_production_rd3443036_mantra_mis100_with_model_serial_uses_mis_profile(): void
    {
        $order = $this->createOrder('RD3443036', 'MIS100V2', 'Mantra MIS100');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertTrue($insight->isActionable());
        $this->assertStringContainsString('product code', $insight->explanation);
        $this->assertStringContainsString('back-side photo', $insight->explanation);
        $this->assertNotSame('No IRA validation rules are configured for this product.', $insight->technicalReason);
    }

    public function test_rd3443036_style_case_gives_clear_ira_guidance(): void
    {
        $order = $this->createOrder('RD3443036', '8011332', 'MIS 100');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertTrue($insight->isActionable());
        $this->assertStringContainsString('MIS', $insight->explanation);
        $this->assertStringContainsString('back-side photo', $insight->explanation);
        $this->assertStringContainsString('WhatsApp', (string) $insight->suggestedAction);
        $this->assertDoesNotMatchRegularExpression('/[\x{0900}-\x{097F}]/u', $insight->explanation);
        $this->assertDoesNotMatchRegularExpression('/[\x{0900}-\x{097F}]/u', (string) $insight->suggestedAction);
    }

    public function test_production_rd3442035_product_label_remains_high_confidence_suspicious(): void
    {
        $order = $this->createOrder('RD3442035', '54SAXXC5514586', 'MFS110');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
    }

    public function test_production_rd3442121_valid_fm220_remains_valid(): void
    {
        $order = $this->createOrder('RD3442121', 'M260779805', 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
    }

    private function createOrder(string $orderId, ?string $serial, string $deviceModel): Order
    {
        $agent = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serial,
            'product_name' => $deviceModel,
            'device_model' => $deviceModel,
            'customer_name' => 'Insight Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced,
            'created_by' => $agent->id,
        ]);
    }
}
