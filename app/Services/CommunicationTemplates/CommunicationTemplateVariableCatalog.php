<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Models\User;

class CommunicationTemplateVariableCatalog
{
    public function __construct(
        private readonly CommunicationTemplateSignatureBuilder $signatures,
    ) {}

    /**
     * @return list<array{key: string, label: string, sample: string}>
     */
    public function all(): array
    {
        return [
            ['key' => 'customer_name', 'label' => 'Customer name', 'sample' => 'Priya Sharma'],
            ['key' => 'order_id', 'label' => 'Order ID', 'sample' => 'RD3450001'],
            ['key' => 'incident_number', 'label' => 'Incident / case number', 'sample' => 'SC-24001'],
            ['key' => 'reference', 'label' => 'Reference', 'sample' => 'SC-24001'],
            ['key' => 'refund_amount', 'label' => 'Refund amount', 'sample' => '₹1,299'],
            ['key' => 'refund_reference', 'label' => 'Refund reference', 'sample' => 'RF-99881'],
            ['key' => 'appointment_date', 'label' => 'Appointment date', 'sample' => '12 Aug 2026'],
            ['key' => 'appointment_time', 'label' => 'Appointment time', 'sample' => '11:00 AM – 12:00 PM'],
            ['key' => 'preferred_date', 'label' => 'Preferred date', 'sample' => '12 Aug 2026'],
            ['key' => 'preferred_time_slot', 'label' => 'Preferred time slot', 'sample' => '11:00 AM – 12:00 PM'],
            ['key' => 'agent_name', 'label' => 'Agent name', 'sample' => 'Support Team'],
            ['key' => 'company_name', 'label' => 'Company name', 'sample' => 'Radium'],
            ['key' => 'booking_url', 'label' => 'Booking URL', 'sample' => 'https://radiumbox.com/book'],
            ['key' => 'driver_download_link', 'label' => 'Driver download link', 'sample' => 'https://radiumbox.com/drivers'],
            ['key' => 'review_url', 'label' => 'Review URL', 'sample' => 'https://radiumbox.com/review'],
            ['key' => 'buy_rd_service_url', 'label' => 'Buy RD Service URL', 'sample' => 'https://radiumbox.com/rd'],
            ['key' => 'buy_device_url', 'label' => 'Buy product URL', 'sample' => 'https://radiumbox.com/shop'],
            ['key' => 'support_email', 'label' => 'Support email', 'sample' => 'support@radiumbox.com'],
            ['key' => 'support_phone', 'label' => 'Support phone', 'sample' => '1800-000-000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sampleMap(): array
    {
        return collect($this->all())
            ->mapWithKeys(fn (array $row): array => [$row['key'] => $row['sample']])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function render(
        string $content,
        array $variables,
        ?CommunicationTemplateGreetingStyle $greeting = null,
        ?CommunicationTemplateSignatureMode $signature = null,
        ?string $agentName = null,
        ?string $companyName = null,
        ?User $actor = null,
    ): string {
        return $this->renderBodyOnly(
            content: $content,
            variables: $variables,
            greeting: $greeting,
            signature: $signature,
            actor: $actor,
            companyName: $companyName,
            agentName: $agentName,
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function renderBodyOnly(
        string $content,
        array $variables,
        ?CommunicationTemplateGreetingStyle $greeting = null,
        ?CommunicationTemplateSignatureMode $signature = null,
        ?User $actor = null,
        ?string $companyName = null,
        ?string $agentName = null,
    ): string {
        $merged = array_merge($this->sampleMap(), $variables);
        $merged['company_name'] = $companyName
            ?? ($actor?->company_name ?: null)
            ?? ($merged['company_name'] ?? config('communication_actions.company_name', 'Radium'));
        $merged['agent_name'] = $agentName
            ?? ($actor?->name ?: null)
            ?? ($merged['agent_name'] ?? 'Support Team');

        $resolvedGreeting = $this->signatures->resolveGreeting($greeting, $actor, $merged);
        $parts = [];

        $greetingText = $resolvedGreeting->render($merged);
        if ($greetingText !== '') {
            $parts[] = '<p>'.e($greetingText).'</p>';
        }

        $body = $content;
        foreach ($merged as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $body = str_replace('{{'.$key.'}}', (string) $value, $body);
            $body = str_replace('{'.$key.'}', (string) $value, $body);
        }
        $parts[] = $body;

        $signatureHtml = $this->signatures->render(
            $signature ?? CommunicationTemplateSignatureMode::CompanyDefault,
            $actor,
            (string) $merged['company_name'],
        );
        if ($signatureHtml !== '') {
            $parts[] = $signatureHtml;
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<string>|array<int, mixed>  $channels
     * @return list<string>
     */
    public function normalizeChannels(array $channels): array
    {
        $normalized = [];

        foreach ($channels as $channel) {
            $value = is_string($channel) ? $channel : (string) $channel;
            $enum = CommunicationTemplateChannel::tryFrom($value);
            if ($enum !== null) {
                $normalized[] = $enum->value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
