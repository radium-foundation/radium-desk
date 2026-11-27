<?php

namespace Tests\Unit\PreShipmentCancellation;

use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreShipmentCancellationOperatorMetadataLengthTest extends TestCase
{
    #[DataProvider('rbp1564ReportedPayloadProvider')]
    public function test_rbp1564_reported_metadata_exceeds_legacy_varchar64(string $refundPreference, string $operatorNotes): void
    {
        $length = PreShipmentCancellationOperatorMetadata::encodedLength($refundPreference, $operatorNotes);

        $this->assertGreaterThan(
            PreShipmentCancellationOperatorMetadata::LEGACY_VARCHAR_LIMIT,
            $length,
            'Encoded psc_meta payload must exceed the legacy varchar(64) blocker_reason column that caused production QueryException.',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rbp1564ReportedPayloadProvider(): array
    {
        return [
            'owner_reported_1646_ist' => ['wallet', 'Updated version not available'],
            'earlier_1239_log' => ['wallet', 'Not required'],
        ];
    }

    public function test_decode_round_trip_for_rbp1564_reported_metadata(): void
    {
        $encoded = PreShipmentCancellationOperatorMetadata::encode('wallet', 'Updated version not available');
        $this->assertNotNull($encoded);

        $decoded = PreShipmentCancellationOperatorMetadata::decode($encoded);
        $this->assertSame('wallet', $decoded['refund_preference']);
        $this->assertSame('Updated version not available', $decoded['operator_notes']);
    }
}
