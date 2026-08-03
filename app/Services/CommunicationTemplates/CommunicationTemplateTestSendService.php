<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Models\CommunicationTemplate;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\NotificationMailSender;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use InvalidArgumentException;

class CommunicationTemplateTestSendService
{
    public function __construct(
        private readonly CommunicationTemplateRuntimeService $runtime,
        private readonly NotificationMailSender $mailSender,
        private readonly NotificationMailTemplateRegistry $mailRegistry,
        private readonly CommunicationTemplateVariableCatalog $variables,
    ) {}

    /**
     * @return array{success: bool, message: string, runtime_source: string, used_fallback: bool}
     */
    public function send(
        CommunicationTemplate $template,
        User $actor,
        string $recipientEmail,
        ?Order $sampleOrder = null,
    ): array {
        if (! $this->mailSender->isEnabled()) {
            throw new InvalidArgumentException('Email delivery is disabled.');
        }

        $recipientEmail = trim($recipientEmail);
        if ($recipientEmail === '' || ! filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid recipient email is required.');
        }

        $variables = $this->variables->sampleMap();
        if ($sampleOrder instanceof Order) {
            $variables['order_id'] = (string) $sampleOrder->order_id;
            $variables['customer_name'] = trim((string) ($sampleOrder->customer_name ?: $variables['customer_name']));
        }
        $variables['agent_name'] = $actor->name;
        $variables['company_name'] = (string) ($actor->company_name ?: config('communication_actions.company_name', 'Radium'));

        $type = NotificationType::tryFrom((string) ($template->notification_type ?? ''));
        if ($type !== null) {
            $rendered = $this->runtime->renderForNotification($type, $variables, $actor);
        } else {
            $version = $template->approvedVersionRecord() ?? $template->currentVersionRecord();
            if ($version === null) {
                throw new InvalidArgumentException('Template has no version to send.');
            }
            $store = $this->runtime->renderStoreVersion($version, $variables, $actor);
            $rendered = [
                ...$store,
                'runtime_source' => 'store',
                'used_fallback' => false,
                'template' => $template,
                'version' => $version,
                'blade_view' => $template->blade_view,
                'error' => null,
            ];
        }

        $definition = $type ? $this->mailRegistry->resolve($type) : null;
        $result = $this->mailSender->send(
            recipientEmail: $recipientEmail,
            mail: new NotificationMail(
                mailSubject: '[TEST] '.($rendered['subject'] ?: $template->name),
                viewName: $definition?->view ?? 'emails.notifications.store-runtime',
                variables: $variables,
                htmlBody: $rendered['html'],
            ),
        );

        if (! $result['success']) {
            return [
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Test send failed.'),
                'runtime_source' => $rendered['runtime_source'],
                'used_fallback' => $rendered['used_fallback'],
            ];
        }

        $this->runtime->recordSuccessfulSend(
            template: $template,
            runtimeSource: $rendered['runtime_source'],
            actor: $actor,
            communicationType: 'test_send',
            usedFallback: $rendered['used_fallback'],
        );

        return [
            'success' => true,
            'message' => 'Test email sent to '.$recipientEmail,
            'runtime_source' => $rendered['runtime_source'],
            'used_fallback' => $rendered['used_fallback'],
        ];
    }
}
