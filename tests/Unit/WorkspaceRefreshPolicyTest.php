<?php

namespace Tests\Unit;

use App\Enums\WorkspaceComponent;
use App\Enums\WorkspaceContext;
use App\Services\WorkspaceRefreshPolicy;
use Tests\TestCase;

class WorkspaceRefreshPolicyTest extends TestCase
{
    private WorkspaceRefreshPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(WorkspaceRefreshPolicy::class);
    }

    public function test_dashboard_assign_refreshes_kpis_and_row(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::Dashboard,
            WorkspaceComponent::Assign,
        );

        $this->assertTrue($effects->refreshKpis);
        $this->assertTrue($effects->replaceRow);
        $this->assertTrue($effects->closeWorkspaceHost);
        $this->assertSame([], $effects->targetSelectors);
    }

    public function test_dashboard_timeline_has_no_side_effects(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::Dashboard,
            WorkspaceComponent::Timeline,
        );

        $this->assertFalse($effects->refreshKpis);
        $this->assertFalse($effects->replaceRow);
        $this->assertSame([], $effects->targetSelectors);
    }

    public function test_service_case_remark_refreshes_activity_timeline(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::ServiceCase,
            WorkspaceComponent::Remark,
        );

        $this->assertFalse($effects->refreshKpis);
        $this->assertFalse($effects->replaceRow);
        $this->assertSame(['#activity-timeline'], $effects->targetSelectors);
        $this->assertTrue($effects->closeWorkspaceHost);
    }

    public function test_service_case_assign_refreshes_timeline_and_header(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::ServiceCase,
            WorkspaceComponent::Assign,
        );

        $this->assertSame(
            ['#activity-timeline', '.service-case-header'],
            $effects->targetSelectors,
        );
    }

    public function test_order_context_includes_order_show_target(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::Order,
            WorkspaceComponent::Resolve,
        );

        $this->assertContains('#activity-timeline', $effects->targetSelectors);
        $this->assertContains('[data-order-show]', $effects->targetSelectors);
    }

    public function test_customer_context_prefers_redirect_for_mutations(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::Customer,
            WorkspaceComponent::Close,
        );

        $this->assertTrue($effects->preferRedirect);
        $this->assertTrue($effects->closeWorkspaceHost);
        $this->assertSame([], $effects->targetSelectors);
    }

    public function test_api_context_has_no_dom_refresh_instructions(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::Api,
            WorkspaceComponent::Assign,
        );

        $this->assertFalse($effects->refreshKpis);
        $this->assertFalse($effects->replaceRow);
        $this->assertSame([], $effects->targetSelectors);
        $this->assertFalse($effects->closeWorkspaceHost);
    }

    public function test_ai_context_renders_fragment_in_host_for_mutations(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::Ai,
            WorkspaceComponent::Remark,
        );

        $this->assertTrue($effects->renderFragmentInHost);
        $this->assertFalse($effects->closeWorkspaceHost);
    }

    public function test_mobile_context_keeps_service_case_targets_with_redirect_preference(): void
    {
        $effects = $this->policy->effectsFor(
            WorkspaceContext::Mobile,
            WorkspaceComponent::Assign,
        );

        $this->assertSame(
            ['#activity-timeline', '.service-case-header'],
            $effects->targetSelectors,
        );
        $this->assertTrue($effects->preferRedirect);
    }
}
