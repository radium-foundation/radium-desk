<?php

namespace App\Services\CommunicationTemplates;

use App\Data\NotificationMessage;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Enums\NotificationType;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Models\User;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommunicationTemplateRuntimeService
{
    public function __construct(
        private readonly NotificationMailTemplateRegistry $mailRegistry,
        private readonly CommunicationTemplateVariableCatalog $variables,
        private readonly CommunicationTemplateSignatureBuilder $signatures,
        private readonly CommunicationTemplateStoreService $store,
    ) {}

    public function findApprovedForNotificationType(NotificationType $type): ?CommunicationTemplate
    {
        return CommunicationTemplate::query()
            ->where('notification_type', $type->value)
            ->whereNotNull('approved_version')
            ->where('approved_version', '>', 0)
            ->orderByDesc('id')
            ->first();
    }

    public function approvedVersion(CommunicationTemplate $template): ?CommunicationTemplateVersion
    {
        $versionNumber = (int) ($template->approved_version ?? 0);
        if ($versionNumber <= 0) {
            return null;
        }

        return $template->versions()->where('version', $versionNumber)->first();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{
     *     subject: string,
     *     html: string,
     *     text: string,
     *     runtime_source: string,
     *     used_fallback: bool,
     *     template: ?CommunicationTemplate,
     *     version: ?CommunicationTemplateVersion,
     *     blade_view: ?string,
     *     error: ?string
     * }
     */
    public function renderForNotification(
        NotificationType $type,
        array $variables,
        ?User $actor = null,
        ?string $subjectOverride = null,
    ): array {
        $definition = $this->mailRegistry->resolve($type);
        $bladeView = $definition?->view;
        $template = $this->findApprovedForNotificationType($type);
        $version = $template instanceof CommunicationTemplate ? $this->approvedVersion($template) : null;

        if ($template instanceof CommunicationTemplate && $version instanceof CommunicationTemplateVersion) {
            try {
                $rendered = $this->renderStoreVersion($version, $variables, $actor, $subjectOverride);

                return [
                    ...$rendered,
                    'runtime_source' => 'store',
                    'used_fallback' => false,
                    'template' => $template,
                    'version' => $version,
                    'blade_view' => $bladeView,
                    'error' => null,
                ];
            } catch (Throwable $e) {
                $this->recordFallback($template, $e->getMessage());
                Log::warning('communication_template.runtime_fallback', [
                    'notification_type' => $type->value,
                    'template_id' => $template->id,
                    'version' => $version->version,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($template instanceof CommunicationTemplate && $version === null) {
            $this->recordFallback($template, 'Approved version missing');
            Log::warning('communication_template.runtime_fallback', [
                'notification_type' => $type->value,
                'template_id' => $template->id,
                'error' => 'Approved version missing',
            ]);
        }

        if ($bladeView === null) {
            throw new \RuntimeException('No email template is configured for this notification.');
        }

        $html = view($bladeView, $variables)->render();
        $subject = $subjectOverride;
        if ($subject === null || $subject === '') {
            $subject = $definition?->subject ?? '';
            foreach ($variables as $key => $value) {
                if (! is_scalar($value)) {
                    continue;
                }
                $subject = str_replace(['{{'.$key.'}}', '{'.$key.'}'], (string) $value, $subject);
            }
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'runtime_source' => 'blade',
            'used_fallback' => $template instanceof CommunicationTemplate,
            'template' => $template,
            'version' => $version,
            'blade_view' => $bladeView,
            'error' => $template instanceof CommunicationTemplate ? ($template->last_error ?? 'store_unavailable') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, html: string, text: string}
     */
    public function renderStoreVersion(
        CommunicationTemplateVersion $version,
        array $variables,
        ?User $actor = null,
        ?string $subjectOverride = null,
    ): array {
        $company = (string) ($variables['company_name'] ?? config('communication_actions.company_name', 'Radium'));
        $greeting = $this->signatures->resolveGreeting(
            $version->greeting_style ?? CommunicationTemplateGreetingStyle::HelloCustomer,
            $actor,
            $variables,
        );

        $body = $this->variables->renderBodyOnly(
            content: $version->body_html,
            variables: $variables,
            greeting: $greeting,
            signature: $version->signature_mode ?? CommunicationTemplateSignatureMode::CompanyDefault,
            actor: $actor,
            companyName: $company,
        );

        $html = view('emails.notifications.store-runtime', [
            'store_body_html' => $body,
            'mail_subject' => $subjectOverride ?: ($version->subject ?? 'Notification'),
        ])->render();

        $subject = $subjectOverride;
        if ($subject === null || $subject === '') {
            $subject = (string) ($version->subject ?? '');
            foreach (array_merge($this->variables->sampleMap(), $variables) as $key => $value) {
                if (! is_scalar($value)) {
                    continue;
                }
                $subject = str_replace(['{{'.$key.'}}', '{'.$key.'}'], (string) $value, $subject);
            }
        }

        if (trim($html) === '') {
            throw new \RuntimeException('Store template rendered empty HTML.');
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ];
    }

    public function recordSuccessfulSend(
        ?CommunicationTemplate $template,
        string $runtimeSource,
        ?User $actor = null,
        ?string $communicationType = null,
        bool $usedFallback = false,
        ?int $editPercent = null,
        ?int $sendDurationMs = null,
    ): void {
        if (! $template instanceof CommunicationTemplate) {
            return;
        }

        $this->store->recordUsage(
            template: $template,
            channel: CommunicationTemplateChannel::Email->value,
            user: $actor,
            communicationType: $communicationType,
            editPercent: $editPercent,
            sendDurationMs: $sendDurationMs,
            runtimeSource: $runtimeSource,
            usedFallback: $usedFallback,
        );

        $template->update([
            'last_send_at' => now(),
            'last_runtime_source' => $runtimeSource,
            'last_error' => $usedFallback ? $template->last_error : null,
            'runtime_source' => $runtimeSource === 'store' ? 'store' : $template->runtime_source,
        ]);
    }

    private function recordFallback(CommunicationTemplate $template, string $error): void
    {
        $template->update([
            'fallback_count' => ((int) $template->fallback_count) + 1,
            'last_fallback_at' => now(),
            'last_error' => mb_substr($error, 0, 2000),
            'last_runtime_source' => 'blade',
        ]);
    }

    /**
     * Convenience for NotificationMessage-driven sends.
     *
     * @return array{
     *     subject: string,
     *     html: string,
     *     text: string,
     *     runtime_source: string,
     *     used_fallback: bool,
     *     template: ?CommunicationTemplate,
     *     version: ?CommunicationTemplateVersion,
     *     blade_view: ?string,
     *     error: ?string
     * }
     */
    public function renderNotificationMessage(NotificationMessage $message, ?string $subjectOverride = null): array
    {
        $variables = $this->mailRegistry->variablesFor($message);
        $subject = $subjectOverride ?? $message->subject ?? $this->mailRegistry->subjectFor($message->type, $message);

        return $this->renderForNotification(
            type: $message->type,
            variables: $variables,
            actor: $message->actor,
            subjectOverride: $subject,
        );
    }
}
