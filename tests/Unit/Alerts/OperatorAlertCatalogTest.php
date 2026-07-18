<?php

namespace Tests\Unit\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\NotificationCategory;
use App\Services\Alerts\OperatorAlertCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperatorAlertCatalogTest extends TestCase
{
    private OperatorAlertCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = new OperatorAlertCatalog;
    }

    #[DataProvider('eventDefinitions')]
    public function test_maps_event_types_to_severity_category_and_delivery_defaults(
        string $eventType,
        AlertSeverity $severity,
        NotificationCategory $category,
        bool $desktopPopup,
        bool $playSound,
        string $iconPrefix,
    ): void {
        $definition = $this->catalog->definitionFor($eventType);

        $this->assertSame($severity, $definition['severity']);
        $this->assertSame($category, $definition['category']);
        $this->assertSame($desktopPopup, $definition['desktop_popup']);
        $this->assertSame($playSound, $definition['play_sound']);
        $this->assertStringStartsWith($iconPrefix, $definition['icon']);
    }

    public function test_make_builds_operator_alert_from_catalog_defaults(): void
    {
        $alert = $this->catalog->make(
            eventType: OperatorAlertCatalog::EVENT_INCOMING_CALL,
            title: 'Incoming Call',
            message: 'Customer Found: RD-100',
            actionUrl: 'https://example.test/incidents/1',
            entityType: 'call',
            entityId: 'call-abc',
            interaction: ['call_id' => 'call-abc'],
        );

        $this->assertSame('Incoming Call', $alert->title);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(NotificationCategory::Ivr, $alert->category);
        $this->assertTrue($alert->desktopPopup);
        $this->assertTrue($alert->playSound);
        $this->assertSame('incoming_call:call:call-abc', $alert->deduplicationKey);
        $this->assertSame(['call_id' => 'call-abc'], $alert->interaction);
    }

    public function test_make_uses_explicit_deduplication_key_when_provided(): void
    {
        $alert = $this->catalog->make(
            eventType: OperatorAlertCatalog::EVENT_SERVICE_CASE_ASSIGNED,
            title: 'Assigned',
            message: 'Case assigned',
            actionUrl: '/incidents/9',
            entityType: 'incident',
            entityId: 9,
            deduplicationKey: 'custom:dedupe:key',
        );

        $this->assertSame('custom:dedupe:key', $alert->deduplicationKey);
        $this->assertSame(AlertSeverity::Medium, $alert->severity);
        $this->assertFalse($alert->playSound);
    }

    public function test_unknown_event_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->catalog->definitionFor('not_a_real_event');
    }

    public function test_supports_returns_false_for_unknown_event(): void
    {
        $this->assertTrue($this->catalog->supports(OperatorAlertCatalog::EVENT_TRANSACTION_COMPLETED));
        $this->assertFalse($this->catalog->supports('not_a_real_event'));
    }

    /**
     * @return array<string, array{0: string, 1: AlertSeverity, 2: NotificationCategory, 3: bool, 4: bool, 5: string}>
     */
    public static function eventDefinitions(): array
    {
        return [
            'incoming call' => [
                OperatorAlertCatalog::EVENT_INCOMING_CALL,
                AlertSeverity::Critical,
                NotificationCategory::Ivr,
                true,
                true,
                'bi-',
            ],
            'high priority' => [
                OperatorAlertCatalog::EVENT_HIGH_PRIORITY_SERVICE_CASE,
                AlertSeverity::High,
                NotificationCategory::Escalation,
                true,
                true,
                'bi-',
            ],
            'assignment' => [
                OperatorAlertCatalog::EVENT_SERVICE_CASE_ASSIGNED,
                AlertSeverity::Medium,
                NotificationCategory::Assignment,
                true,
                false,
                'bi-',
            ],
            'transaction' => [
                OperatorAlertCatalog::EVENT_TRANSACTION_COMPLETED,
                AlertSeverity::Medium,
                NotificationCategory::Finance,
                true,
                false,
                'bi-',
            ],
            'leave submitted' => [
                OperatorAlertCatalog::EVENT_LEAVE_REQUEST_SUBMITTED,
                AlertSeverity::Medium,
                NotificationCategory::LeaveApprovals,
                true,
                false,
                'bi-',
            ],
        ];
    }
}
