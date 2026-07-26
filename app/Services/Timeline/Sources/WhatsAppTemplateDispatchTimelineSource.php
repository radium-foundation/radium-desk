<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineEventType;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Order;
use App\Models\WhatsAppTemplateDispatch;
use Illuminate\Support\Collection;

class WhatsAppTemplateDispatchTimelineSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        return WhatsAppTemplateDispatch::query()
            ->with('triggeredBy')
            ->where('order_id', $this->order->id)
            ->whereIn('status', [
                WhatsAppTemplateDispatchStatus::Sent,
                WhatsAppTemplateDispatchStatus::Failed,
            ])
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (WhatsAppTemplateDispatch $dispatch): TimelineEvent => $this->mapDispatch($dispatch))
            ->values();
    }

    private function mapDispatch(WhatsAppTemplateDispatch $dispatch): TimelineEvent
    {
        $occurredAt = $dispatch->dispatched_at ?? $dispatch->created_at;
        $language = $this->languageLabelForTemplateKey($dispatch->template_key);

        return new TimelineEvent(
            type: TimelineEventType::WhatsAppTemplateSent,
            occurredAt: $occurredAt,
            title: 'WhatsApp Template Sent',
            actor: new TimelineActor($this->actorNameForDispatch($dispatch)),
            dedupeKey: 'whatsapp-template-dispatch:'.$dispatch->id,
            statusLabel: $dispatch->status->timelineStatusLabel(),
            statusVariant: $dispatch->status->statusVariant(),
            summaryFields: array_values(array_filter([
                filled($dispatch->template_display_name) ? [
                    'label' => 'Template',
                    'value' => $dispatch->template_display_name,
                ] : null,
                filled($language) ? [
                    'label' => 'Language',
                    'value' => $language,
                ] : null,
                filled($dispatch->template_key) ? [
                    'label' => 'Template Key',
                    'value' => $dispatch->template_key,
                ] : null,
                filled($dispatch->template_purpose) ? [
                    'label' => 'Purpose',
                    'value' => $dispatch->template_purpose,
                ] : null,
                [
                    'label' => 'Trigger',
                    'value' => $dispatch->trigger_source->label(),
                ],
            ])),
        );
    }

    private function actorNameForDispatch(WhatsAppTemplateDispatch $dispatch): string
    {
        if ($dispatch->trigger_source === WhatsAppTemplateTriggerSource::Manual
            && filled($dispatch->triggeredBy?->name)) {
            return $dispatch->triggeredBy->name;
        }

        return match ($dispatch->trigger_source) {
            WhatsAppTemplateTriggerSource::Manual => 'Support',
            WhatsAppTemplateTriggerSource::Ira,
            WhatsAppTemplateTriggerSource::Automation,
            WhatsAppTemplateTriggerSource::Scheduler,
            WhatsAppTemplateTriggerSource::Webhook => 'IRA',
        };
    }

    private function languageLabelForTemplateKey(?string $templateKey): ?string
    {
        if (! filled($templateKey)) {
            return null;
        }

        /** @var array<string, mixed>|null $cfg */
        $cfg = config('interakt.templates.'.$templateKey);
        if (! is_array($cfg)) {
            return null;
        }

        $code = strtolower(trim((string) ($cfg['language_code'] ?? 'en')));

        return match (true) {
            $code === 'hi', str_contains($code, 'hindi') => 'Hindi',
            $code === 'en', str_contains($code, 'english') => 'English',
            $code !== '' => ucfirst($code),
            default => null,
        };
    }
}
