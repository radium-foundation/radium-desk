<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\NotificationType;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationMailTemplateRegistry;

class CommunicationTemplateComparisonService
{
    public function __construct(
        private readonly NotificationMailTemplateRegistry $mailRegistry,
        private readonly CommunicationTemplateRuntimeService $runtime,
        private readonly CommunicationTemplatePreviewService $preview,
        private readonly CommunicationTemplateVariableCatalog $variables,
    ) {}

    /**
     * @return array{
     *     blade_subject: ?string,
     *     blade_html: string,
     *     store_subject: ?string,
     *     store_html: string,
     *     identical: bool,
     *     diff_ratio: float,
     *     sample_variables: array<string, string>
     * }
     */
    public function compare(CommunicationTemplate $template, ?User $actor = null): array
    {
        $samples = $this->variables->sampleMap();
        $bladeHtml = '';
        $bladeSubject = null;

        if (is_string($template->blade_view) && $template->blade_view !== '') {
            $bladeHtml = view($template->blade_view, $samples)->render();
        }

        if (is_string($template->notification_type) && $template->notification_type !== '') {
            $type = NotificationType::tryFrom($template->notification_type);
            if ($type !== null) {
                $definition = $this->mailRegistry->resolve($type);
                $bladeSubject = $definition?->subject;
                if ($bladeSubject !== null) {
                    foreach ($samples as $key => $value) {
                        $bladeSubject = str_replace(['{{'.$key.'}}', '{'.$key.'}'], $value, $bladeSubject);
                    }
                }
            }
        }

        $version = $template->approvedVersionRecord() ?? $template->currentVersionRecord();
        $store = $version
            ? $this->runtime->renderStoreVersion($version, $samples, $actor)
            : ['subject' => null, 'html' => '<p>No store version.</p>', 'text' => ''];

        $bladeNorm = $this->normalize($bladeHtml);
        $storeNorm = $this->normalize($store['html']);
        $identical = $bladeNorm === $storeNorm;
        $diffRatio = $this->diffRatio($bladeNorm, $storeNorm);

        return [
            'blade_subject' => $bladeSubject,
            'blade_html' => $bladeHtml,
            'store_subject' => $store['subject'],
            'store_html' => $store['html'],
            'identical' => $identical,
            'diff_ratio' => $diffRatio,
            'sample_variables' => $samples,
        ];
    }

    private function normalize(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }

    private function diffRatio(string $a, string $b): float
    {
        if ($a === $b) {
            return 0.0;
        }
        if ($a === '' || $b === '') {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return round(1 - ($percent / 100), 4);
    }
}
