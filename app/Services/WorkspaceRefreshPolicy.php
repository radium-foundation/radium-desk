<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRefreshEffects;
use App\Enums\WorkspaceComponent;
use App\Enums\WorkspaceContext;
use App\Models\Incident;

class WorkspaceRefreshPolicy
{
    public function effectsFor(
        WorkspaceContext $context,
        WorkspaceComponent $component,
        ?Incident $incident = null,
    ): WorkspaceRefreshEffects {
        return match ($context) {
            WorkspaceContext::Dashboard => $this->dashboardEffects($component),
            WorkspaceContext::ServiceCase => $this->serviceCaseEffects($component),
            WorkspaceContext::Order => $this->orderEffects($component),
            WorkspaceContext::Customer => $this->customerEffects($component),
            WorkspaceContext::Mobile => $this->mobileEffects($component),
            WorkspaceContext::Api => $this->apiEffects($component),
            WorkspaceContext::Ai => $this->aiEffects($component),
        };
    }

    private function dashboardEffects(WorkspaceComponent $component): WorkspaceRefreshEffects
    {
        return match ($component) {
            WorkspaceComponent::Timeline => new WorkspaceRefreshEffects,
            WorkspaceComponent::BatchTransaction => new WorkspaceRefreshEffects(
                refreshKpis: true,
            ),
            WorkspaceComponent::BatchDeviceModel => new WorkspaceRefreshEffects(
                refreshKpis: true,
            ),
            default => new WorkspaceRefreshEffects(
                refreshKpis: true,
                replaceRow: true,
                closeWorkspaceHost: true,
            ),
        };
    }

    private function serviceCaseEffects(WorkspaceComponent $component): WorkspaceRefreshEffects
    {
        return match ($component) {
            WorkspaceComponent::Assign, WorkspaceComponent::Action, WorkspaceComponent::Close => new WorkspaceRefreshEffects(
                targetSelectors: [
                    $this->target('activity_timeline'),
                    $this->target('service_case_header'),
                ],
                closeWorkspaceHost: true,
            ),
            WorkspaceComponent::Remark, WorkspaceComponent::Timeline => new WorkspaceRefreshEffects(
                targetSelectors: [
                    $this->target('activity_timeline'),
                ],
                closeWorkspaceHost: $component !== WorkspaceComponent::Timeline,
            ),
        };
    }

    private function orderEffects(WorkspaceComponent $component): WorkspaceRefreshEffects
    {
        return match ($component) {
            WorkspaceComponent::Timeline => new WorkspaceRefreshEffects(
                targetSelectors: [
                    $this->target('activity_timeline'),
                ],
                closeWorkspaceHost: false,
            ),
            default => new WorkspaceRefreshEffects(
                targetSelectors: [
                    $this->target('activity_timeline'),
                    $this->target('order_show'),
                ],
                closeWorkspaceHost: true,
            ),
        };
    }

    private function customerEffects(WorkspaceComponent $component): WorkspaceRefreshEffects
    {
        return match ($component) {
            WorkspaceComponent::Timeline => new WorkspaceRefreshEffects(
                targetSelectors: [
                    $this->target('activity_timeline'),
                ],
                closeWorkspaceHost: false,
            ),
            WorkspaceComponent::RequestSerialNumber => new WorkspaceRefreshEffects(
                closeWorkspaceHost: true,
            ),
            WorkspaceComponent::RequestCorrectSerial => new WorkspaceRefreshEffects(
                closeWorkspaceHost: true,
            ),
            WorkspaceComponent::CustomerNotResponding => new WorkspaceRefreshEffects(
                closeWorkspaceHost: true,
            ),
            WorkspaceComponent::LinkOrder => new WorkspaceRefreshEffects(
                closeWorkspaceHost: true,
            ),
            WorkspaceComponent::CorrectCustomerDetails => new WorkspaceRefreshEffects(
                closeWorkspaceHost: true,
            ),
            WorkspaceComponent::CorrectSerialNumber => new WorkspaceRefreshEffects(
                closeWorkspaceHost: true,
            ),
            WorkspaceComponent::CommunicationAction => new WorkspaceRefreshEffects(
                closeWorkspaceHost: true,
            ),
            default => new WorkspaceRefreshEffects(
                preferRedirect: true,
                closeWorkspaceHost: true,
            ),
        };
    }

    private function mobileEffects(WorkspaceComponent $component): WorkspaceRefreshEffects
    {
        $effects = $this->serviceCaseEffects($component);

        return new WorkspaceRefreshEffects(
            refreshKpis: $effects->refreshKpis,
            replaceRow: $effects->replaceRow,
            targetSelectors: $effects->targetSelectors,
            closeWorkspaceHost: $effects->closeWorkspaceHost,
            preferRedirect: $component !== WorkspaceComponent::Timeline,
            renderFragmentInHost: $effects->renderFragmentInHost,
        );
    }

    private function apiEffects(WorkspaceComponent $component): WorkspaceRefreshEffects
    {
        return new WorkspaceRefreshEffects(
            closeWorkspaceHost: false,
        );
    }

    private function aiEffects(WorkspaceComponent $component): WorkspaceRefreshEffects
    {
        return match ($component) {
            WorkspaceComponent::Timeline => new WorkspaceRefreshEffects(
                targetSelectors: [
                    $this->target('activity_timeline'),
                ],
                closeWorkspaceHost: false,
            ),
            default => new WorkspaceRefreshEffects(
                renderFragmentInHost: true,
                closeWorkspaceHost: false,
            ),
        };
    }

    private function target(string $key): string
    {
        return (string) config("workspace.targets.{$key}");
    }
}
