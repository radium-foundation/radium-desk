<?php

namespace App\Models;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IntakeChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Services\IncomingEmail\IncomingEmailPreviewExtractor;

class IncomingEmailMessage extends Model
{
    protected $fillable = [
        'intake_channel',
        'mailbox',
        'channel',
        'provider',
        'provider_message_id',
        'rfc_message_id',
        'thread_id',
        'from_email',
        'from_name',
        'to_emails',
        'subject',
        'preview',
        'received_at',
        'attachment_count',
        'headers',
        'labels',
        'raw_payload',
        'status',
        'ignore_reason',
        'classification',
        'importance',
        'learning_owner_user_id',
        'suggested_assignee_user_id',
        'ira_decision',
        'ira_confidence',
        'ira_reason',
        'ira_explanation',
        'matched_learning_rule_id',
        'disposition',
        'disposition_reason',
        'disposed_at',
        'disposed_by_user_id',
        'incident_id',
        'order_id',
        'processed_at',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'intake_channel' => IntakeChannel::class,
            'status' => IncomingEmailMessageStatus::class,
            'classification' => IncomingEmailClassification::class,
            'importance' => IncomingEmailImportance::class,
            'disposition' => IncomingEmailDisposition::class,
            'to_emails' => 'array',
            'headers' => 'array',
            'labels' => 'array',
            'raw_payload' => 'array',
            'ira_explanation' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'disposed_at' => 'datetime',
            'attachment_count' => 'integer',
            'ira_confidence' => 'integer',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function learningOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learning_owner_user_id');
    }

    public function suggestedAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_assignee_user_id');
    }

    public function matchedLearningRule(): BelongsTo
    {
        return $this->belongsTo(IncomingEmailLearningRule::class, 'matched_learning_rule_id');
    }

    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by_user_id');
    }

    public function incidentLink(): HasOne
    {
        return $this->hasOne(IncidentIncomingEmailLink::class);
    }

    public function outgoingReplies(): HasMany
    {
        return $this->hasMany(OutgoingEmailMessage::class, 'in_reply_to_incoming_email_message_id');
    }

    /**
     * @return list<array{attachment_id: ?string, filename: ?string, mime_type: ?string, size: ?int}>
     */
    public function attachmentMetadata(): array
    {
        $attachments = $this->raw_payload['attachments'] ?? [];

        if (! is_array($attachments)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $attachment): ?array {
                if (! is_array($attachment)) {
                    return null;
                }

                return [
                    'attachment_id' => isset($attachment['attachment_id']) && is_string($attachment['attachment_id'])
                        ? $attachment['attachment_id']
                        : null,
                    'filename' => isset($attachment['filename']) && is_string($attachment['filename'])
                        ? $attachment['filename']
                        : null,
                    'mime_type' => isset($attachment['mime_type']) && is_string($attachment['mime_type'])
                        ? $attachment['mime_type']
                        : null,
                    'size' => isset($attachment['size']) && is_numeric($attachment['size'])
                        ? (int) $attachment['size']
                        : null,
                ];
            },
            $attachments,
        )));
    }

    public function displayPreview(): ?string
    {
        if (filled($this->preview)) {
            return (string) $this->preview;
        }

        $legacyText = $this->legacyBodyText();

        if ($legacyText === null) {
            return null;
        }

        return app(IncomingEmailPreviewExtractor::class)->extract($legacyText);
    }

    public function hasLegacyStoredBody(): bool
    {
        return $this->legacyBodyText() !== null || $this->legacyBodyHtml() !== null;
    }

    public function legacyBodyText(): ?string
    {
        $body = $this->raw_payload['body_text'] ?? null;

        return is_string($body) && trim($body) !== '' ? $body : null;
    }

    public function legacyBodyHtml(): ?string
    {
        $body = $this->raw_payload['body_html'] ?? null;

        return is_string($body) && trim($body) !== '' ? $body : null;
    }
}
