<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Models\User;

class CommunicationTemplatePreviewService
{
    public function __construct(
        private readonly CommunicationTemplateVariableCatalog $variables,
    ) {}

    /**
     * @param  array<string, string>  $overrides
     * @return array{subject: ?string, html: string, text: string}
     */
    public function previewVersion(
        CommunicationTemplateVersion $version,
        array $overrides = [],
        ?User $actor = null,
    ): array {
        $company = (string) ($overrides['company_name'] ?? config('communication_actions.company_name', 'Radium'));
        $agent = $actor?->name ?? (string) ($overrides['agent_name'] ?? 'Support Team');

        $html = $this->variables->render(
            content: $version->body_html,
            variables: $overrides,
            greeting: $version->greeting_style ?? CommunicationTemplateGreetingStyle::HelloCustomer,
            signature: $version->signature_mode ?? CommunicationTemplateSignatureMode::CompanyDefault,
            agentName: $agent,
            companyName: $company,
            actor: $actor,
        );

        $subject = $version->subject;
        if (is_string($subject)) {
            foreach (array_merge($this->variables->sampleMap(), $overrides) as $key => $value) {
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
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array{subject: ?string, html: string, text: string}
     */
    public function previewTemplate(CommunicationTemplate $template, array $overrides = [], ?User $actor = null): array
    {
        $version = $template->currentVersionRecord();

        if (! $version instanceof CommunicationTemplateVersion) {
            return ['subject' => null, 'html' => '<p>No version available.</p>', 'text' => 'No version available.'];
        }

        return $this->previewVersion($version, $overrides, $actor);
    }
}
