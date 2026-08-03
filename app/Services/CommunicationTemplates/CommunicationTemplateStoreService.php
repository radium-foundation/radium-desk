<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Enums\NotificationType;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateUsage;
use App\Models\CommunicationTemplateVersion;
use App\Models\User;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CommunicationTemplateStoreService
{
    public function __construct(
        private readonly CommunicationTemplateVariableCatalog $variables,
        private readonly NotificationMailTemplateRegistry $mailRegistry,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     category: string,
     *     channels: list<string>,
     *     subject?: ?string,
     *     greeting_style?: ?string,
     *     body_html: string,
     *     signature_mode?: ?string,
     *     change_reason?: ?string,
     *     status?: ?string,
     *     blade_view?: ?string,
     *     notification_type?: ?string,
     *     key?: ?string,
     *     is_reply_playbook?: bool,
     *     playbook_scope?: ?string,
     *     owner_user_id?: ?int
     * }  $data
     */
    public function create(array $data, User $actor): CommunicationTemplate
    {
        $channels = $this->variables->normalizeChannels($data['channels'] ?? [CommunicationTemplateChannel::Email->value]);
        if ($channels === []) {
            throw new InvalidArgumentException('At least one channel is required.');
        }

        $key = trim((string) ($data['key'] ?? ''));
        if ($key === '') {
            $key = Str::slug((string) $data['name']);
        }

        return DB::transaction(function () use ($data, $actor, $channels, $key): CommunicationTemplate {
            $status = CommunicationTemplateStatus::tryFrom((string) ($data['status'] ?? 'draft'))
                ?? CommunicationTemplateStatus::Draft;

            $template = CommunicationTemplate::query()->create([
                'key' => $key,
                'name' => trim((string) $data['name']),
                'category' => CommunicationTemplateCategory::from((string) $data['category']),
                'channels' => $channels,
                'status' => $status,
                'current_version' => 0,
                'approved_version' => null,
                'blade_view' => $data['blade_view'] ?? null,
                'notification_type' => $data['notification_type'] ?? null,
                'is_reply_playbook' => (bool) ($data['is_reply_playbook'] ?? false),
                'playbook_scope' => $data['playbook_scope'] ?? null,
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'runtime_source' => 'blade',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->appendVersion($template, $data, $actor, $channels, 'Initial version');

            if ($status === CommunicationTemplateStatus::Approved) {
                $template->update([
                    'approved_version' => $template->current_version,
                    'runtime_source' => 'store',
                ]);
            }

            return $template->fresh(['updater', 'versions']);
        });
    }

    /**
     * @param  array{
     *     name?: ?string,
     *     category?: ?string,
     *     channels?: list<string>,
     *     subject?: ?string,
     *     greeting_style?: ?string,
     *     body_html: string,
     *     signature_mode?: ?string,
     *     change_reason?: ?string,
     *     is_reply_playbook?: bool,
     *     playbook_scope?: ?string
     * }  $data
     */
    public function revise(CommunicationTemplate $template, array $data, User $actor): CommunicationTemplate
    {
        $channels = isset($data['channels'])
            ? $this->variables->normalizeChannels($data['channels'])
            : (is_array($template->channels) ? $template->channels : [CommunicationTemplateChannel::Email->value]);

        if ($channels === []) {
            throw new InvalidArgumentException('At least one channel is required.');
        }

        return DB::transaction(function () use ($template, $data, $actor, $channels): CommunicationTemplate {
            if (isset($data['name'])) {
                $template->name = trim((string) $data['name']);
            }
            if (isset($data['category'])) {
                $template->category = CommunicationTemplateCategory::from((string) $data['category']);
            }
            if (array_key_exists('is_reply_playbook', $data)) {
                $template->is_reply_playbook = (bool) $data['is_reply_playbook'];
            }
            if (array_key_exists('playbook_scope', $data)) {
                $template->playbook_scope = $data['playbook_scope'];
            }

            // Never mutate the approved snapshot in place — edit creates a draft tip version.
            $template->channels = $channels;
            $template->status = CommunicationTemplateStatus::Draft;
            $template->updated_by = $actor->id;
            $template->save();

            $this->appendVersion(
                $template,
                $data,
                $actor,
                $channels,
                trim((string) ($data['change_reason'] ?? '')) !== ''
                    ? (string) $data['change_reason']
                    : 'Draft revision',
            );

            return $template->fresh(['updater', 'versions']);
        });
    }

    public function approve(CommunicationTemplate $template, User $actor): CommunicationTemplate
    {
        if ($template->current_version <= 0) {
            throw new RuntimeException('Cannot approve a template without a version.');
        }

        $template->update([
            'status' => CommunicationTemplateStatus::Approved,
            'approved_version' => $template->current_version,
            'runtime_source' => 'store',
            'updated_by' => $actor->id,
            'last_error' => null,
        ]);

        return $template->fresh();
    }

    public function deprecate(CommunicationTemplate $template, User $actor): CommunicationTemplate
    {
        $template->update([
            'status' => CommunicationTemplateStatus::Deprecated,
            'runtime_source' => 'blade',
            'updated_by' => $actor->id,
        ]);

        return $template->fresh();
    }

    public function rollback(CommunicationTemplate $template, int $versionNumber, User $actor, ?string $reason = null): CommunicationTemplate
    {
        $source = $template->versions()->where('version', $versionNumber)->first();

        if (! $source instanceof CommunicationTemplateVersion) {
            throw new InvalidArgumentException('Version not found.');
        }

        return $this->revise($template, [
            'subject' => $source->subject,
            'greeting_style' => $source->greeting_style?->value,
            'body_html' => $source->body_html,
            'signature_mode' => $source->signature_mode?->value,
            'channels' => is_array($source->channels) ? $source->channels : $template->channels,
            'change_reason' => $reason ?: 'Rollback to version '.$versionNumber,
        ], $actor);
    }

    public function recordUsage(
        CommunicationTemplate $template,
        string $channel,
        ?User $user = null,
        ?string $communicationType = null,
        ?int $editPercent = null,
        ?int $sendDurationMs = null,
        ?string $runtimeSource = null,
        bool $usedFallback = false,
    ): void {
        $versionNumber = (int) ($template->approved_version ?: $template->current_version);
        $version = $versionNumber > 0
            ? $template->versions()->where('version', $versionNumber)->first()
            : $template->currentVersionRecord();

        CommunicationTemplateUsage::query()->create([
            'communication_template_id' => $template->id,
            'communication_template_version_id' => $version?->id,
            'used_by' => $user?->id,
            'channel' => $channel,
            'communication_type' => $communicationType,
            'edit_percent' => $editPercent,
            'send_duration_ms' => $sendDurationMs,
            'runtime_source' => $runtimeSource,
            'used_fallback' => $usedFallback,
            'used_at' => now(),
        ]);

        $template->update([
            'usage_count' => $template->usage_count + 1,
            'last_used_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $channels
     */
    private function appendVersion(
        CommunicationTemplate $template,
        array $data,
        User $actor,
        array $channels,
        string $reason,
    ): CommunicationTemplateVersion {
        $next = ((int) $template->current_version) + 1;
        $body = trim((string) ($data['body_html'] ?? ''));

        if ($body === '') {
            throw new InvalidArgumentException('Body is required.');
        }

        $greeting = CommunicationTemplateGreetingStyle::tryFrom((string) ($data['greeting_style'] ?? ''))
            ?? CommunicationTemplateGreetingStyle::HelloCustomer;
        $signature = CommunicationTemplateSignatureMode::tryFrom((string) ($data['signature_mode'] ?? ''))
            ?? CommunicationTemplateSignatureMode::CompanyDefault;

        $version = CommunicationTemplateVersion::query()->create([
            'communication_template_id' => $template->id,
            'version' => $next,
            'subject' => isset($data['subject']) ? trim((string) $data['subject']) : null,
            'greeting_style' => $greeting,
            'body_html' => $body,
            'signature_mode' => $signature,
            'channels' => $channels,
            'variables' => $this->extractVariables($body.' '.(string) ($data['subject'] ?? '')),
            'change_reason' => $reason,
            'created_by' => $actor->id,
        ]);

        $template->update([
            'current_version' => $next,
            'updated_by' => $actor->id,
        ]);

        return $version;
    }

    /**
     * @return list<string>
     */
    private function extractVariables(string $content): array
    {
        preg_match_all('/\{\{?\s*([a-zA-Z0-9_]+)\s*\}?\}/', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @return list<NotificationType>
     */
    public function inventoryNotificationTypes(): array
    {
        $types = [];

        foreach (NotificationType::cases() as $type) {
            if ($this->mailRegistry->resolve($type) !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
