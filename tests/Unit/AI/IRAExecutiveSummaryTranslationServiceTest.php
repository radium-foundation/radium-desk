<?php

namespace Tests\Unit\AI;

use App\Services\AI\IRAExecutiveSummaryTranslationService;
use Tests\TestCase;

class IRAExecutiveSummaryTranslationServiceTest extends TestCase
{
    public function test_current_owner_sentence_does_not_swallow_following_sentence(): void
    {
        $english = 'Current owner: Jayram. Device serial number is still missing.';

        $hindi = app(IRAExecutiveSummaryTranslationService::class)->translateNarrative($english);

        $this->assertStringContainsString('Jayram', $hindi);
        $this->assertStringContainsString('अभी भी नहीं मिला', $hindi);
        $this->assertStringNotContainsString('Device serial number is still missing', $hindi);
    }

    public function test_translates_current_operations_narrative_to_spoken_hindi(): void
    {
        $english = 'This is a high-priority service case for FM 220. Progress is blocked because the device serial number has not been provided. Current owner: Jayram. Device serial number is still missing. Priority handling is required because this case is marked high impact.';

        $hindi = app(IRAExecutiveSummaryTranslationService::class)->translateNarrative($english);

        $this->assertMatchesRegularExpression('/[\x{0900}-\x{097F}]/u', $hindi);
        $this->assertStringContainsString('हाई-प्रायोरिटी', $hindi);
        $this->assertStringContainsString('सीरियल नंबर', $hindi);
        $this->assertStringContainsString('FM 220', $hindi);
        $this->assertStringContainsString('Jayram', $hindi);
        $this->assertStringNotContainsString('सत्यापन अपेक्षित', $hindi);
        $this->assertStringNotContainsString('कार्यकारी', $hindi);
    }

    public function test_preserves_business_entities_and_ids(): void
    {
        $english = 'This is a critical-priority service case for FM220. Ownership changed from Shubhanshi → IRA, increasing the risk of further delay. SLA is already overdue, so further delay increases customer escalation risk.';

        $hindi = app(IRAExecutiveSummaryTranslationService::class)->translateNarrative($english);

        $this->assertStringContainsString('FM220', $hindi);
        $this->assertStringContainsString('Shubhanshi', $hindi);
        $this->assertStringContainsString('IRA', $hindi);
        $this->assertStringContainsString('SLA', $hindi);
        $this->assertMatchesRegularExpression('/[\x{0900}-\x{097F}]/u', $hindi);
    }

    public function test_payload_translates_narrative_only(): void
    {
        $opinion = 'This case cannot move forward until the device serial number is confirmed with the customer.';
        $recommendation = 'Verify the customer serial number and reconnect with the customer.';

        $translated = app(IRAExecutiveSummaryTranslationService::class)->translatePayloadToHindi([
            'executive_summary' => [
                'This is an open service case for FM220. Serial number still needs verification.',
            ],
            'opinion' => $opinion,
            'recommendation' => $recommendation,
        ]);

        $this->assertMatchesRegularExpression('/[\x{0900}-\x{097F}]/u', $translated['executive_summary'][0]);
        $this->assertStringContainsString('FM220', $translated['executive_summary'][0]);
        $this->assertSame($opinion, $translated['opinion']);
        $this->assertSame($recommendation, $translated['recommendation']);
    }

    public function test_appointment_overdue_and_no_reply_style_sentences(): void
    {
        $english = 'The scheduled support appointment is overdue and the visit has not been completed. Customer has not replied.';

        $hindi = app(IRAExecutiveSummaryTranslationService::class)->translateNarrative($english);

        $this->assertStringContainsString('अपॉइंटमेंट', $hindi);
        $this->assertStringContainsString('जवाब नहीं', $hindi);
    }

    public function test_legacy_summary_sentences_still_translate(): void
    {
        $translated = app(IRAExecutiveSummaryTranslationService::class)->translatePayloadToHindi([
            'executive_summary' => [
                'Customer purchased an FM220 and currently has one active repair.',
                'The device serial number is still missing, causing service delay.',
            ],
            'opinion' => 'Leave opinion in English.',
            'recommendation' => 'Leave recommendation in English.',
        ]);

        $this->assertStringContainsString('ग्राहक ने', $translated['executive_summary'][0]);
        $this->assertStringContainsString('FM220', $translated['executive_summary'][0]);
        $this->assertStringContainsString('सीरियल नंबर', $translated['executive_summary'][1]);
        $this->assertSame('Leave opinion in English.', $translated['opinion']);
        $this->assertSame('Leave recommendation in English.', $translated['recommendation']);
    }
}
