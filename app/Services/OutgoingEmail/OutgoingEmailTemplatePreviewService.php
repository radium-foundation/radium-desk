<?php

namespace App\Services\OutgoingEmail;

use App\Data\NotificationMessage;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Enums\NotificationType;
use App\Models\CommunicationTemplate;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationTemplates\CommunicationTemplateRuntimeService;
use App\Services\CommunicationTemplates\CommunicationTemplateSignatureBuilder;
use App\Services\CommunicationTemplates\CommunicationTemplateVariableCatalog;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use InvalidArgumentException;

class OutgoingEmailTemplatePreviewService
{
    public function __construct(
        private readonly CommunicationTemplateRuntimeService $runtime,
        private readonly CommunicationTemplateVariableCatalog $variables,
        private readonly CommunicationTemplateSignatureBuilder $signatures,
    ) {}

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function availableTemplates(?User $actor = null): array
    {
        $templates = [
            ['key' => 'blank', 'label' => 'Blank Reply', 'group' => 'blank'],
        ];

        $playbooks = CommunicationTemplate::query()
            ->where('is_reply_playbook', true)
            ->whereNotNull('approved_version')
            ->where('approved_version', '>', 0)
            ->where(function ($query) use ($actor): void {
                $query->where('playbook_scope', 'global')
                    ->orWhere(function ($inner) use ($actor): void {
                        $inner->where('playbook_scope', 'personal')
                            ->where('owner_user_id', $actor?->id);
                    })
                    ->orWhere(function ($inner): void {
                        $inner->whereNull('playbook_scope')
                            ->whereNotNull('notification_type');
                    });
            })
            ->where(function ($query): void {
                $query->where('status', CommunicationTemplateStatus::Approved->value)
                    ->orWhereNotNull('approved_version');
            })
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        foreach ($playbooks as $playbook) {
            $group = $playbook->playbook_scope === 'personal' ? 'personal' : 'suggested';
            $templates[] = [
                'key' => 'playbook:'.$playbook->id,
                'label' => $playbook->name.' ('.$playbook->category->label().')',
                'group' => $group,
            ];
        }

        // Legacy notification-type keys remain available as suggested playbooks when store rows exist.
        foreach ([
            NotificationType::RequestSerialNumber,
            NotificationType::CustomerWaitingFollowup,
            NotificationType::CallbackSchedule,
            NotificationType::SupportAppointmentBooked,
            NotificationType::ServiceCaseClosed,
            NotificationType::DriverInstallationGuide,
            NotificationType::RefundConfirmation,
        ] as $type) {
            $already = collect($templates)->contains(fn (array $row): bool => str_ends_with($row['key'], $type->value)
                || ($row['key'] === $type->value));
            if ($already) {
                continue;
            }
            if ($this->runtime->findApprovedForNotificationType($type) === null) {
                continue;
            }
            $templates[] = [
                'key' => $type->value,
                'label' => str_replace('_', ' ', ucfirst($type->value)),
                'group' => 'suggested',
            ];
        }

        return $templates;
    }

    /**
     * @return array{subject: string, body_html: string, template_key: string}
     */
    public function preview(
        IncomingEmailMessage $message,
        string $templateKey,
        User $actor,
        array $extraVariables = [],
    ): array {
        $templateKey = trim($templateKey);

        if ($templateKey === '' || $templateKey === 'blank') {
            return [
                'subject' => $this->defaultReplySubject($message),
                'body_html' => $this->blankReplyBody($message, $actor),
                'template_key' => 'blank',
            ];
        }

        if (str_starts_with($templateKey, 'playbook:')) {
            $id = (int) substr($templateKey, strlen('playbook:'));
            $playbook = CommunicationTemplate::query()->find($id);
            if (! $playbook instanceof CommunicationTemplate || (int) ($playbook->approved_version ?? 0) <= 0) {
                throw new InvalidArgumentException('Reply playbook is not approved.');
            }
            if ($playbook->playbook_scope === 'personal' && (int) $playbook->owner_user_id !== (int) $actor->id) {
                throw new InvalidArgumentException('Personal playbook is not available.');
            }

            $version = $playbook->approvedVersionRecord();
            if ($version === null) {
                throw new InvalidArgumentException('Reply playbook version missing.');
            }

            $incident = $this->resolveIncident($message);
            $order = $this->resolveOrder($message, $incident);
            $variables = array_merge($this->variables->sampleMap(), $extraVariables, [
                'customer_name' => trim((string) ($order?->customer_name ?: 'Customer')),
                'order_id' => trim((string) ($order?->order_id ?: '')),
                'incident_number' => trim((string) ($incident?->reference_no ?: '')),
                'reference' => trim((string) ($incident?->reference_no ?: '')),
                'agent_name' => $actor->name,
                'company_name' => (string) ($actor->company_name ?: config('communication_actions.company_name', 'Radium')),
            ]);

            $rendered = $this->runtime->renderStoreVersion($version, $variables, $actor);

            return [
                'subject' => $rendered['subject'] !== '' ? $rendered['subject'] : $this->defaultReplySubject($message),
                'body_html' => $rendered['html'],
                'template_key' => $templateKey,
            ];
        }

        $type = NotificationType::tryFrom($templateKey);
        if ($type === null) {
            throw new InvalidArgumentException('Unknown reply playbook: '.$templateKey);
        }

        $incident = $this->resolveIncident($message);
        $order = $this->resolveOrder($message, $incident);
        if ($incident === null || $order === null) {
            throw new InvalidArgumentException('Template replies require a linked incident and order.');
        }

        $notification = new NotificationMessage(
            type: $type,
            customer: $order,
            incident: $incident,
            variables: $extraVariables,
            actor: $actor,
        );

        $rendered = $this->runtime->renderNotificationMessage($notification);

        return [
            'subject' => $rendered['subject'] !== '' ? $rendered['subject'] : $this->defaultReplySubject($message),
            'body_html' => $rendered['html'],
            'template_key' => $type->value,
        ];
    }

    public function defaultReplySubject(IncomingEmailMessage $message): string
    {
        $subject = trim((string) $message->subject);

        if ($subject === '') {
            return 'Re: Your support request';
        }

        if (preg_match('/^re:\s*/i', $subject) === 1) {
            return $subject;
        }

        return 'Re: '.$subject;
    }

    private function blankReplyBody(IncomingEmailMessage $message, User $actor): string
    {
        $order = $this->resolveOrder($message, $this->resolveIncident($message));
        $variables = [
            'customer_name' => trim((string) ($order?->customer_name ?: 'Customer')),
            'agent_name' => $actor->name,
            'company_name' => (string) ($actor->company_name ?: config('communication_actions.company_name', 'Radium')),
        ];

        $greeting = $this->signatures->resolveGreeting(
            CommunicationTemplateGreetingStyle::CompanyDefault,
            $actor,
            $variables,
        );

        $parts = [];
        $greetingText = $greeting->render($variables);
        if ($greetingText !== '') {
            $parts[] = '<p>'.e($greetingText).'</p>';
        }
        $parts[] = '<p></p>';
        $parts[] = $this->signatures->render(
            CommunicationTemplateSignatureMode::UserSignature,
            $actor,
            $variables['company_name'],
        );

        return implode("\n", $parts);
    }

    private function resolveIncident(IncomingEmailMessage $message): ?Incident
    {
        $message->loadMissing('incident');

        return $message->incident;
    }

    private function resolveOrder(IncomingEmailMessage $message, ?Incident $incident): ?Order
    {
        $message->loadMissing('order');

        if ($message->order instanceof Order) {
            return $message->order;
        }

        $incident?->loadMissing('order');

        return $incident?->order;
    }
}
