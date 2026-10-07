<?php

namespace Tests\Unit\Reports\CaMonthly;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Reports\CaMonthly\CaMonthlyReportEInvoiceEvidenceDisplay;
use App\Reports\CaMonthly\CaMonthlyReportGstinFormatStatusDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportEInvoiceEvidenceDisplayTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    public function test_irn_generated_when_valid_irn_present(): void
    {
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::Submitted->value,
            'irn' => 'a'.str_repeat('b', 63),
            'ack_no' => 'ACK-1',
            'response_payload' => [
                'outcome' => 'success',
                'payload' => ['status_desc' => '[{"errorCode":"0","errorMessage":"Success"}]'],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame(
            CaMonthlyReportEInvoiceEvidenceDisplay::STATUS_IRN_GENERATED,
            CaMonthlyReportEInvoiceEvidenceDisplay::generationStatus($invoice),
        );
        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice));
    }

    public function test_provider_rejection_preserves_code_and_message(): void
    {
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::PermanentFailure->value,
            'response_payload' => [
                'outcome' => 'permanent_failure',
                'payload' => [
                    'reason' => 'missing_irn',
                    'status_desc' => '[{"errorCode":"3028","errorMessage":"GSTIN -07AAAAA0000A1Z5 is invalid."}]',
                ],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame(
            CaMonthlyReportEInvoiceEvidenceDisplay::STATUS_PROVIDER_REJECTION,
            CaMonthlyReportEInvoiceEvidenceDisplay::generationStatus($invoice),
        );
        $this->assertSame('3028', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame(
            'GSTIN -07AAAAA0000A1Z5 is invalid.',
            CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice),
        );
    }

    public function test_skipped_preserves_skip_reason_and_sorted_gaps(): void
    {
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::Skipped->value,
            'response_payload' => [
                'skip_reason' => 'irp_fields_incomplete',
                'gaps' => ['missing_buyer_pin', 'missing_buyer_loc', 'buyer_address_exceeds_irp_limit'],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame(
            CaMonthlyReportEInvoiceEvidenceDisplay::STATUS_SKIPPED,
            CaMonthlyReportEInvoiceEvidenceDisplay::generationStatus($invoice),
        );
        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame(
            'irp_fields_incomplete — buyer_address_exceeds_irp_limit, missing_buyer_loc, missing_buyer_pin',
            CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice),
        );
    }

    public function test_no_e_invoice_record_is_not_reported_as_failed(): void
    {
        $invoice = $this->makeTaxInvoice();

        $this->assertSame(
            CaMonthlyReportEInvoiceEvidenceDisplay::STATUS_NO_RECORD,
            CaMonthlyReportEInvoiceEvidenceDisplay::generationStatus($invoice),
        );
        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame(
            CaMonthlyReportEInvoiceEvidenceDisplay::REASON_NO_RECORD,
            CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice),
        );
    }

    public function test_malformed_response_payload_does_not_export_raw_json(): void
    {
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::PermanentFailure->value,
            'response_payload' => [
                'payload' => [
                    'status_desc' => 'not-json',
                ],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame(
            CaMonthlyReportEInvoiceEvidenceDisplay::STATUS_PROVIDER_REJECTION,
            CaMonthlyReportEInvoiceEvidenceDisplay::generationStatus($invoice),
        );
        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice));
    }

    public function test_multiple_status_desc_entries_are_joined_deterministically(): void
    {
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::PermanentFailure->value,
            'response_payload' => [
                'payload' => [
                    'status_desc' => [
                        ['errorCode' => '2227', 'errorMessage' => 'Line 1 mismatch'],
                        ['errorCode' => '2240', 'errorMessage' => 'Line 2 rate incorrect'],
                    ],
                ],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame('2227; 2240', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame(
            'Line 1 mismatch; Line 2 rate incorrect',
            CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice),
        );
    }

    public function test_missing_error_code_still_exports_message(): void
    {
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::PermanentFailure->value,
            'response_payload' => [
                'payload' => [
                    'status_desc' => '[{"errorMessage":"Provider rejected submission"}]',
                ],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame(
            'Provider rejected submission',
            CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice),
        );
    }

    public function test_missing_error_message_still_exports_code(): void
    {
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::PermanentFailure->value,
            'response_payload' => [
                'payload' => [
                    'status_desc' => '[{"errorCode":"3039"}]',
                ],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame('3039', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
        $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice));
    }

    public function test_gstin_format_status_remains_independent(): void
    {
        $invoice = $this->makeTaxInvoice(['buyer_gstin' => '07AAICP1128M1Z9']);
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::PermanentFailure->value,
            'response_payload' => [
                'payload' => [
                    'status_desc' => '[{"errorCode":"3028","errorMessage":"GSTIN -07AAICP1128M1Z9 is invalid."}]',
                ],
            ],
        ]);
        $invoice->load('eInvoiceRecord');

        $this->assertSame(
            CaMonthlyReportGstinFormatStatusDisplay::FORMAT_VALID,
            CaMonthlyReportGstinFormatStatusDisplay::forInvoice($invoice),
        );
        $this->assertSame('3028', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
    }

    public function test_september_2026_forty_eight_case_fixture_mapping(): void
    {
        $fixturePath = base_path('tests/fixtures/ca-sep-2026-gstin-no-irn-evidence.json');
        $this->assertFileExists($fixturePath);

        $fixture = json_decode((string) file_get_contents($fixturePath), true);
        $this->assertIsArray($fixture);
        $this->assertSame(48, $fixture['count'] ?? null);

        $providerFailures = 0;
        $skipped = 0;
        $codeCounts = [];

        foreach ($fixture['cases'] as $case) {
            $invoice = $this->makeTaxInvoice([
                'invoice_number' => (string) $case['invoice_number'],
                'buyer_gstin' => (string) $case['buyer_gstin'],
            ]);

            $responsePayload = $this->responsePayloadFromFixtureCase($case);
            EInvoiceRecord::query()->create([
                'invoice_id' => $invoice->id,
                'provider' => (string) ($case['provider'] ?? 'whitebooks'),
                'status' => (string) $case['einvoice_status'],
                'response_payload' => $responsePayload,
            ]);
            $invoice->load('eInvoiceRecord');

            if (($case['einvoice_status'] ?? '') === 'permanent_failure') {
                $providerFailures++;
                $this->assertSame(
                    CaMonthlyReportEInvoiceEvidenceDisplay::STATUS_PROVIDER_REJECTION,
                    CaMonthlyReportEInvoiceEvidenceDisplay::generationStatus($invoice),
                );
                $code = CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice);
                $this->assertSame((string) $case['provider_error_code'], $code);
                $this->assertSame(
                    (string) $case['provider_response_reason'],
                    CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice),
                );
                $codeCounts[$code] = ($codeCounts[$code] ?? 0) + 1;
            } elseif (($case['einvoice_status'] ?? '') === 'skipped') {
                $skipped++;
                $this->assertSame(
                    CaMonthlyReportEInvoiceEvidenceDisplay::STATUS_SKIPPED,
                    CaMonthlyReportEInvoiceEvidenceDisplay::generationStatus($invoice),
                );
                $this->assertSame('', CaMonthlyReportEInvoiceEvidenceDisplay::responseCode($invoice));
                $this->assertStringStartsWith('irp_fields_incomplete', CaMonthlyReportEInvoiceEvidenceDisplay::responseReason($invoice));
            }
        }

        $this->assertSame(29, $providerFailures);
        $this->assertSame(19, $skipped);
        ksort($codeCounts);
        $expected = [
            '2227' => 6,
            '2240' => 1,
            '3028' => 11,
            '3038' => 1,
            '3039' => 2,
            '3074' => 5,
            '3075' => 1,
            '5002' => 2,
        ];
        ksort($expected);
        $this->assertSame($expected, $codeCounts);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function responsePayloadFromFixtureCase(array $case): array
    {
        if (($case['einvoice_status'] ?? '') === 'skipped') {
            return [
                'skip_reason' => 'irp_fields_incomplete',
                'gaps' => $this->gapsFromFixtureReason((string) ($case['provider_response_reason'] ?? '')),
            ];
        }

        return [
            'outcome' => (string) ($case['outcome'] ?? 'permanent_failure'),
            'provider_status' => (string) ($case['provider_status'] ?? 'permanent_failure'),
            'payload' => [
                'reason' => (string) ($case['internal_reason'] ?? 'missing_irn'),
                'status_cd' => (string) ($case['status_cd'] ?? '0'),
                'status_desc' => (string) ($case['status_desc'] ?? ''),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function gapsFromFixtureReason(string $reason): array
    {
        if (! str_contains($reason, ' — ')) {
            return [];
        }

        [, $gapText] = explode(' — ', $reason, 2);
        $gaps = array_map('trim', explode(',', $gapText));

        return array_values(array_filter($gaps, fn (string $gap): bool => $gap !== ''));
    }
}
