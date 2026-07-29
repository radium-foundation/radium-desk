<?php

namespace Tests\Unit\Context;

use App\Contracts\Context\ProvidesContextScope;
use App\Data\Context\ContextBadge;
use App\Enums\ContextScope;
use App\Support\Context\ContextTransparency;
use App\Support\Customer360\CaseIntelligenceV2OverviewPresenter;
use App\Support\Customer360\Customer360CardCatalog;
use App\Support\Customer360\Customer360CommunicationActionStatusPresenter;
use App\Support\Customer360\Customer360HealthCardPresenter;
use App\Support\Customer360\Customer360InsightsPresenter;
use App\Support\Customer360\Customer360IraPanelPresenter;
use App\Support\Customer360\Customer360OverflowMenuPresenter;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContextTransparencyFoundationTest extends TestCase
{
    public function test_context_scope_enum_exposes_all_required_values(): void
    {
        $this->assertSame(
            ['case', 'order', 'device', 'customer'],
            ContextScope::values(),
        );

        foreach (ContextScope::cases() as $scope) {
            $this->assertNotSame('', $scope->label());
            $this->assertNotSame('', $scope->colorToken());
            $this->assertNotSame('', $scope->defaultIcon());
        }
    }

    public function test_context_badge_serializes_presentation_metadata(): void
    {
        $badge = ContextBadge::forScope(ContextScope::Case, 'Timeline');

        $this->assertSame(ContextScope::Case, $badge->scope);
        $this->assertSame('Timeline', $badge->label);
        $this->assertSame(ContextScope::Case->defaultIcon(), $badge->icon);
        $this->assertSame(ContextScope::Case->colorToken(), $badge->colorToken);
        $this->assertSame([
            'scope' => 'case',
            'label' => 'Timeline',
            'icon' => ContextScope::Case->defaultIcon(),
            'color_token' => ContextScope::Case->colorToken(),
        ], $badge->toArray());
    }

    public function test_feature_flag_defaults_to_disabled(): void
    {
        Config::set('context_transparency.enabled', false);

        $this->assertFalse(ContextTransparency::enabled());
        $this->assertNull(ContextTransparency::badgeFor(ContextScope::Order));
        $this->assertNull(Customer360CardCatalog::badgeFor(Customer360CardCatalog::TIMELINE));
    }

    public function test_feature_flag_exposes_metadata_when_enabled(): void
    {
        Config::set('context_transparency.enabled', true);

        $this->assertTrue(ContextTransparency::enabled());

        $badge = ContextTransparency::badgeFor(ContextScope::Device, 'Serial');
        $this->assertInstanceOf(ContextBadge::class, $badge);
        $this->assertSame(ContextScope::Device, $badge->scope);
        $this->assertSame('Serial', $badge->label);

        $timelineBadge = Customer360CardCatalog::badgeFor(Customer360CardCatalog::TIMELINE);
        $this->assertInstanceOf(ContextBadge::class, $timelineBadge);
        $this->assertSame(ContextScope::Case, $timelineBadge->scope);
    }

    public function test_customer360_card_catalog_annotates_required_scopes(): void
    {
        $expectations = [
            Customer360CardCatalog::TIMELINE => ContextScope::Case,
            Customer360CardCatalog::REFUND_ACTION => ContextScope::Case,
            Customer360CardCatalog::SUPPORT_APPOINTMENTS => ContextScope::Case,
            Customer360CardCatalog::DEVICE_WARRANTY => ContextScope::Order,
            Customer360CardCatalog::DEVICE_SERIAL => ContextScope::Device,
            Customer360CardCatalog::PREVIOUS_REFUNDS => ContextScope::Customer,
            Customer360CardCatalog::HEALTH_RECENT_CALLS => ContextScope::Customer,
        ];

        foreach ($expectations as $key => $scope) {
            $this->assertSame(
                $scope,
                Customer360CardCatalog::intendedScope($key),
                "Card [{$key}] intended scope mismatch.",
            );
        }

        $keys = Customer360CardCatalog::keyed()->keys()->all();
        $this->assertContains(Customer360CardCatalog::EXECUTIVE_SUMMARY, $keys);
        $this->assertContains(Customer360CardCatalog::IRA_PANEL, $keys);
        $this->assertContains(Customer360CardCatalog::PREVIOUS_ORDERS, $keys);
    }

    public function test_catalog_covers_all_four_scopes(): void
    {
        foreach (ContextScope::cases() as $scope) {
            $this->assertTrue(
                Customer360CardCatalog::forScope($scope)->isNotEmpty(),
                "Expected at least one card annotated as {$scope->value}.",
            );
        }
    }

    /**
     * @return list<class-string<ProvidesContextScope>>
     */
    public static function scopedPresenterProvider(): array
    {
        return [
            [Customer360HealthCardPresenter::class, ContextScope::Case],
            [Customer360IraPanelPresenter::class, ContextScope::Case],
            [CaseIntelligenceV2OverviewPresenter::class, ContextScope::Case],
            [Customer360OverflowMenuPresenter::class, ContextScope::Case],
            [Customer360CommunicationActionStatusPresenter::class, ContextScope::Case],
            [Customer360InsightsPresenter::class, ContextScope::Customer],
        ];
    }

    /**
     * @param  class-string<ProvidesContextScope>  $presenterClass
     */
    #[DataProvider('scopedPresenterProvider')]
    public function test_presenters_expose_context_scope_without_affecting_flag_off_badge(
        string $presenterClass,
        ContextScope $expectedScope,
    ): void {
        Config::set('context_transparency.enabled', false);

        /** @var ProvidesContextScope $presenter */
        $presenter = $this->app->make($presenterClass);

        $this->assertInstanceOf(ProvidesContextScope::class, $presenter);
        $this->assertSame($expectedScope, $presenter->contextScope());
        $this->assertNull($presenter->contextBadge());
    }

    /**
     * @param  class-string<ProvidesContextScope>  $presenterClass
     */
    #[DataProvider('scopedPresenterProvider')]
    public function test_presenters_expose_context_badge_when_flag_enabled(
        string $presenterClass,
        ContextScope $expectedScope,
    ): void {
        Config::set('context_transparency.enabled', true);

        /** @var ProvidesContextScope $presenter */
        $presenter = $this->app->make($presenterClass);

        $badge = $presenter->contextBadge();
        $this->assertInstanceOf(ContextBadge::class, $badge);
        $this->assertSame($expectedScope, $badge->scope);
        $this->assertSame($expectedScope->label(), $badge->label);
    }
}
