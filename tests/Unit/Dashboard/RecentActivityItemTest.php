<?php

namespace Tests\Unit\Dashboard;

use App\Data\RecentActivityItem;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecentActivityItemTest extends TestCase
{
    #[DataProvider('actionLabelProvider')]
    public function test_action_label_stays_within_twelve_characters(string $title, string $expected): void
    {
        $item = $this->makeItem(title: $title);

        $this->assertSame($expected, $item->actionLabel());
        $this->assertLessThanOrEqual(12, mb_strlen($item->actionLabel()));
    }

    public function test_status_mark_is_quiet_for_success_and_marks_failures(): void
    {
        $this->assertNull($this->makeItem(indicatorVariant: 'success')->statusMark());
        $this->assertNull($this->makeItem(indicatorVariant: 'communication')->statusMark());
        $this->assertSame('fail', $this->makeItem(indicatorVariant: 'error')->statusMark());
        $this->assertSame('warn', $this->makeItem(indicatorVariant: 'warning')->statusMark());
    }

    public function test_incident_label_uses_middle_dot_separator(): void
    {
        $item = $this->makeItem(
            incidentReference: 'SC17454',
            orderReference: 'RD3459717',
        );

        $this->assertSame('SC17454 · RD3459717', $item->incidentLabel());
    }

    public function test_communication_action_uses_channel_and_incident_reference(): void
    {
        $item = $this->makeItem(
            title: 'Communication Sent',
            indicatorVariant: 'communication',
            typePill: 'WhatsApp',
            incidentReference: 'SC17526',
        );

        $this->assertSame('WA→SC17526', $item->actionLabel());
        $this->assertSame('', $item->channelBadge());
    }

    public function test_communication_action_falls_back_without_incident_reference(): void
    {
        $item = $this->makeItem(
            title: 'Communication Sent',
            indicatorVariant: 'communication',
            typePill: 'WhatsApp',
        );

        $this->assertSame('Comm Sent', $item->actionLabel());
        $this->assertSame('WA', $item->channelBadge());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function actionLabelProvider(): array
    {
        return [
            'communication sent' => ['Communication Sent', 'Comm Sent'],
            'enrichment failed' => ['Enrichment Failed', 'Enrich Fail'],
            'driver guide' => ['Driver Guide Sent', 'DG Sent'],
            'whatsapp sent' => ['WhatsApp Message Sent', 'WA Sent'],
            'refund completed' => ['Refund Completed', 'Refunded'],
            'assigned' => ['Assigned', 'Assigned'],
        ];
    }

    private function makeItem(
        string $title = 'Assigned',
        string $indicatorVariant = 'muted',
        ?string $typePill = null,
        ?string $incidentReference = null,
        ?string $orderReference = null,
    ): RecentActivityItem {
        return new RecentActivityItem(
            stream: 'team',
            title: $title,
            typePill: $typePill,
            indicatorVariant: $indicatorVariant,
            incidentReference: $incidentReference,
            orderReference: $orderReference,
            customerName: null,
            entityIncidentId: null,
            entityReference: null,
            occurredAt: Carbon::parse('2026-07-24 09:00:00'),
            compactTime: '1m',
            exactTime: '09:00',
            actorName: 'Ravi Kumar',
            isAutomation: false,
        );
    }
}
