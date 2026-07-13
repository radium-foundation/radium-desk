<?php

namespace App\Support\Customer360;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Enums\CommunicationActionKey;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionTargetProviderRegistry;
use App\Services\Customer360\Customer360ActionVisibilityService;
use App\Services\WorkspaceActionDialogService;
use Illuminate\Support\Collection;

final class Customer360OverflowMenuPresenter
{
    public function __construct(
        private readonly Customer360ActionVisibilityService $visibilityService,
        private readonly WorkspaceActionDialogService $workspaceActionDialogService,
        private readonly CommunicationActionEligibilityService $communicationActionEligibilityService,
        private readonly CommunicationActionTargetProviderRegistry $communicationActionTargetProviderRegistry,
    ) {}

    /**
     * @param  array{requested?: bool, requested_at_label?: string|null}  $serialRequestState
     * @param  array{requested?: bool, requested_at_label?: string|null}  $correctSerialRequestState
     * @return array{
     *     groups: list<array{label: string, icon: string, items: list<array<string, mixed>>}>,
     *     paletteActions: list<array<string, mixed>>,
     * }
     */
    public function build(
        Incident $incident,
        User $user,
        ?Order $order = null,
        array $serialRequestState = [],
        array $correctSerialRequestState = [],
        ?Collection $supportAppointments = null,
    ): array {
        $visibility = $this->visibilityService->forIncident($incident, $user);
        $capabilities = $this->workspaceActionDialogService->capabilities($incident, $user);
        $requestCorrectSerialMenu = RequestCorrectSerialMenuPresenter::resolve(
            (bool) $visibility['canRequestCorrectSerial'],
            $correctSerialRequestState,
        );

        $groups = [
            $this->communicationGroup($incident, $user, $visibility, $requestCorrectSerialMenu, $serialRequestState),
            $this->customerGroup($visibility),
            $this->caseGroup($capabilities),
            $this->appointmentsGroup($incident, $supportAppointments),
            $this->relatedGroup($incident, $order, $visibility),
            $this->financeGroup($user),
        ];

        $groups = array_values(array_filter(
            $groups,
            fn (array $group): bool => $group['items'] !== [],
        ));

        return [
            'groups' => $groups,
            'paletteActions' => $this->paletteActions($groups),
        ];
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @param  array{
     *     visible: bool,
     *     type: 'trigger'|'status'|'hidden',
     *     label: string,
     *     enabled: bool,
     *     status: string,
     * }  $requestCorrectSerialMenu
     * @param  array{requested?: bool}  $serialRequestState
     * @return array{label: string, icon: string, items: list<array<string, mixed>>}
     */
    private function communicationGroup(
        Incident $incident,
        User $user,
        array $visibility,
        array $requestCorrectSerialMenu,
        array $serialRequestState,
    ): array {
        $items = [];

        if ($visibility['canRequestSerialNumber'] && ! ($serialRequestState['requested'] ?? false)) {
            $items[] = $this->triggerItem(
                id: 'request-serial',
                label: 'Request Serial',
                icon: 'scan-barcode',
                trigger: 'request-serial',
                keywords: ['serial', 'request', 'communication'],
            );
        }

        if ($requestCorrectSerialMenu['visible']
            && $requestCorrectSerialMenu['type'] === 'trigger'
            && $requestCorrectSerialMenu['status'] === 're-request') {
            $items[] = $this->triggerItem(
                id: 'request-correct-serial',
                label: 'Re-request Serial',
                icon: 'camera',
                trigger: 'request-correct-serial',
                keywords: ['serial', 'request', 're-request', 'communication'],
            );
        }

        if ($visibility['canCustomerNotResponding']) {
            $items[] = $this->triggerItem(
                id: 'customer-not-responding',
                label: 'Customer Not Responding',
                icon: 'hourglass',
                trigger: 'customer-not-responding',
                keywords: ['customer', 'waiting', 'callback', 'communication'],
            );
        }

        $communicationMenuItems = collect($this->communicationActionEligibilityService->menuItems($incident, $user));

        if ($this->communicationActionTargetProviderRegistry->hasEligibleCenterAction($incident, $user)) {
            $items[] = $this->triggerItem(
                id: 'communication-center',
                label: 'Communication',
                icon: 'message-square',
                trigger: 'communication-action',
                keywords: $this->communicationCenterKeywords($incident, $user),
            );
        }

        $refundAction = $communicationMenuItems
            ->firstWhere('key', CommunicationActionKey::RefundConfirmation->value);

        if (($refundAction['eligible'] ?? false) === true) {
            $items[] = [
                'id' => 'communication-'.$refundAction['key'],
                'type' => 'communication',
                'label' => $refundAction['name'],
                'icon' => Customer360OverflowMenuLucideIcon::resolve($refundAction['icon']),
                'trigger' => 'communication-action',
                'communicationActionKey' => $refundAction['key'],
                'keywords' => $this->communicationKeywords($refundAction),
            ];
        }

        if ($visibility['hideWorkflowActions'] && $requestCorrectSerialMenu['status'] === 'pending') {
            $items[] = [
                'id' => 'request-correct-serial-pending',
                'type' => 'status',
                'label' => $requestCorrectSerialMenu['label'],
                'icon' => 'circle-check',
            ];
        }

        return [
            'label' => 'Communication',
            'icon' => '💬',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @return array{label: string, icon: string, items: list<array<string, mixed>>}
     */
    private function customerGroup(array $visibility): array
    {
        $items = [];

        if ($visibility['showIdentityCorrectionActions']
            && $visibility['correctCustomerDetailsEligibility']['allowed']) {
            $items[] = $this->triggerItem(
                id: 'correct-customer',
                label: 'Correct Customer',
                icon: 'user-cog',
                trigger: 'correct-customer-details',
                keywords: ['customer', 'details', 'identity'],
                shortcut: 'correct-customer',
            );
        }

        if ($visibility['showIdentityCorrectionActions']
            && $visibility['correctSerialNumberEligibility']['allowed']) {
            $items[] = $this->triggerItem(
                id: 'correct-serial',
                label: 'Correct Serial',
                icon: 'barcode',
                trigger: 'correct-serial-number',
                keywords: ['serial', 'identity'],
                shortcut: 'correct-serial',
            );
        }

        return [
            'label' => 'Customer',
            'icon' => '👤',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @return array{label: string, icon: string, items: list<array<string, mixed>>}
     */
    private function caseGroup(array $capabilities): array
    {
        $items = [];

        if ($capabilities['assign']) {
            $items[] = $this->triggerItem(
                id: 'assign-engineer',
                label: 'Assign Engineer',
                icon: 'user-check',
                trigger: 'action',
                workspaceActionType: 'assign',
                keywords: ['assign', 'engineer', 'owner'],
            );
        }

        if ($capabilities['escalate']) {
            $items[] = $this->triggerItem(
                id: 'escalate-case',
                label: 'Escalate',
                icon: 'circle-arrow-up',
                trigger: 'action',
                workspaceActionType: 'escalate',
                keywords: ['escalate', 'supervisor'],
                accent: 'warning',
            );
        }

        if ($capabilities['reopen']) {
            $items[] = $this->triggerItem(
                id: 'reopen-case',
                label: 'Reopen Case',
                icon: 'rotate-ccw',
                trigger: 'action',
                workspaceActionType: 'reopen',
                keywords: ['reopen', 'restore'],
            );
        }

        if ($capabilities['close']) {
            $items[] = array_merge(
                $this->triggerItem(
                    id: 'close-case',
                    label: 'Close Case',
                    icon: 'circle-check',
                    trigger: 'action',
                    workspaceActionType: 'close',
                    keywords: ['close', 'resolve', 'complete'],
                ),
                [
                    'dividerBefore' => true,
                    'destructive' => true,
                ],
            );
        }

        return [
            'label' => 'Case',
            'icon' => '👨‍🔧',
            'items' => $items,
        ];
    }

    /**
     * @return array{label: string, icon: string, items: list<array<string, mixed>>}
     */
    private function appointmentsGroup(Incident $incident, ?Collection $supportAppointments): array
    {
        $items = [
            $this->tabItem(
                id: 'schedule-appointment',
                label: 'Schedule Appointment',
                icon: 'calendar-plus',
                tab: 'overview',
                anchor: 'support-appointments',
                keywords: ['appointment', 'schedule'],
            ),
        ];

        if ($supportAppointments !== null && $supportAppointments->isNotEmpty()) {
            $items[] = $this->linkItem(
                id: 'view-appointments',
                label: 'View Appointments',
                icon: 'calendar',
                href: route('incidents.show', $incident).'#support-appointments',
                keywords: ['appointment', 'view'],
            );
        }

        return [
            'label' => 'Appointments',
            'icon' => '📅',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @return array{label: string, icon: string, items: list<array<string, mixed>>}
     */
    private function relatedGroup(Incident $incident, ?Order $order, array $visibility): array
    {
        $items = [];

        if ($visibility['canLinkOrder']) {
            $items[] = $this->triggerItem(
                id: 'link-order',
                label: 'Link Order',
                icon: 'link',
                trigger: 'link-order',
                keywords: ['order', 'link', 'inquiry'],
            );
        }

        if ($order !== null) {
            $items[] = $this->linkItem(
                id: 'open-order',
                label: 'Open Order',
                icon: 'external-link',
                href: route('orders.show', $order),
                keywords: ['order'],
            );
        }

        $items[] = $this->linkItem(
            id: 'open-case',
            label: 'Open Case',
            icon: 'folder-open',
            href: route('incidents.show', $incident),
            keywords: ['case', 'incident'],
        );

        return [
            'label' => 'Related',
            'icon' => '📂',
            'items' => $items,
        ];
    }

    /**
     * @return array{label: string, icon: string, items: list<array<string, mixed>>}
     */
    private function financeGroup(User $user): array
    {
        $items = [];

        if ($user->can('refunds.create')) {
            $items[] = $this->linkItem(
                id: 'refund',
                label: 'Refund',
                icon: 'rotate-ccw',
                href: route('refunds.create'),
                keywords: ['refund', 'finance'],
                destructive: true,
            );
        }

        return [
            'label' => 'Finance',
            'icon' => '💰',
            'items' => $items,
        ];
    }

    /**
     * @param  list<array{label: string, icon: string, items: list<array<string, mixed>>}>  $groups
     * @return list<array<string, mixed>>
     */
    private function paletteActions(array $groups): array
    {
        return collect($groups)
            ->flatMap(fn (array $group): array => $group['items'])
            ->reject(fn (array $item): bool => ($item['type'] ?? '') === 'status')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    private function triggerItem(
        string $id,
        string $label,
        string $icon,
        string $trigger,
        array $keywords = [],
        ?string $workspaceActionType = null,
        ?string $shortcut = null,
        ?string $accent = null,
    ): array {
        return array_filter([
            'id' => $id,
            'type' => 'trigger',
            'label' => $label,
            'icon' => $icon,
            'trigger' => $trigger,
            'workspaceActionType' => $workspaceActionType,
            'shortcut' => $shortcut,
            'accent' => $accent,
            'keywords' => $keywords,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    private function linkItem(
        string $id,
        string $label,
        string $icon,
        string $href,
        array $keywords = [],
        bool $destructive = false,
    ): array {
        return array_filter([
            'id' => $id,
            'type' => 'link',
            'label' => $label,
            'icon' => $icon,
            'href' => $href,
            'keywords' => $keywords,
            'destructive' => $destructive ? true : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    private function tabItem(
        string $id,
        string $label,
        string $icon,
        string $tab,
        ?string $anchor = null,
        array $keywords = [],
    ): array {
        return array_filter([
            'id' => $id,
            'type' => 'tab',
            'label' => $label,
            'icon' => $icon,
            'tab' => $tab,
            'anchor' => $anchor,
            'keywords' => $keywords,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<string>
     */
    private function communicationCenterKeywords(Incident $incident, User $user): array
    {
        return collect($this->communicationActionTargetProviderRegistry->eligibleCenterActions($incident, $user))
            ->flatMap(fn (array $action): array => [
                'communication',
                strtolower($action['name']),
            ])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     channels: list<array{value: string, label: string}>,
     * }  $action
     * @return list<string>
     */
    private function communicationKeywords(array $action): array
    {
        return collect([$action['name'], $action['description']])
            ->merge(collect($action['channels'] ?? [])->pluck('label'))
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => strtolower((string) $value))
            ->unique()
            ->values()
            ->all();
    }
}
