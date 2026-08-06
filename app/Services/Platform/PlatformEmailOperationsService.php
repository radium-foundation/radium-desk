<?php

namespace App\Services\Platform;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Enums\IraMemoryStatus;
use App\Enums\PlatformHealthStatus;
use App\Models\AuditLog;
use App\Models\GmailSyncMessageFailure;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use App\Services\Operations\OperationsGmailHealthService;
use App\Support\Platform\PlatformCacheAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Platform “Email Operations” zone aggregates — trusted production metrics only.
 */
class PlatformEmailOperationsService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_EMAIL_OPERATIONS_OVERVIEW;

    public const CACHE_TTL_SECONDS = PlatformCachePolicy::TTL_PRIORITY_3;

    private const ACTIVITY_EVENTS = [
        'incoming_email.linked',
        'incoming_email.promoted_to_service_case',
        'incoming_email.ignored',
        'incoming_email.needs_review',
        'incoming_email.processing_failed',
        'incoming_email.routed',
        'incoming_email.learning_action',
        'incoming_email.learning_rule_applied',
        'incoming_email.assignment_fallback',
        'incoming_email.disposition',
    ];

    public function __construct(
        private readonly OperationsGmailHealthService $gmailHealth,
        private readonly IncomingEmailIntakeCounterService $intakeCounters,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function cachedOverview(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && ($cached['available'] ?? false) === true) {
            return $cached;
        }

        return [
            'available' => false,
            'overall_status' => PlatformHealthStatus::Disabled->value,
            'generated_at' => null,
            'enabled' => (bool) config('inbound_email.enabled'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = $this->cachedOverview();
            if ($cached['available']) {
                return $cached;
            }
        }

        $payload = $this->buildOverview();
        $old = Cache::get(self::CACHE_KEY);
        PlatformCacheAudit::write(
            service: self::class,
            method: 'overview',
            cacheKey: self::CACHE_KEY,
            oldPayload: is_array($old) ? $old : null,
            newPayload: $payload,
        );
        Cache::put(self::CACHE_KEY, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    public function forgetCache(): void
    {
        PlatformCacheAudit::forget(self::class, 'forgetCache', self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(): array
    {
        $enabled = (bool) config('inbound_email.enabled');
        $now = now();
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        if (! $enabled || ! Schema::hasTable('incoming_email_messages')) {
            return [
                'available' => true,
                'enabled' => false,
                'overall_status' => PlatformHealthStatus::Disabled->value,
                'generated_at' => $now->toIso8601String(),
                'kpis' => [],
                'pipeline' => [],
                'case_creation' => [],
                'classification' => [],
                'assignment' => [],
                'ira_memory' => null,
                'exceptions' => [],
                'activity' => [],
                'links' => $this->links(),
            ];
        }

        $received = $this->countReceived($start, $end);
        $processed = $this->countProcessed($start, $end);
        $needsHuman = $this->intakeCounters->needsHumanCount();
        $newCases = $this->countAudits('incoming_email.promoted_to_service_case', $start, $end);
        $linked = $this->countAudits('incoming_email.linked', $start, $end);
        $ignored = $this->countIgnored($start, $end);
        $failedMessages = $this->countFailedMessages($start, $end);
        $gmailFailures = $this->countOpenGmailFailures();
        $failures = $failedMessages + $gmailFailures;

        $kpis = [
            $this->kpi('received', 'Emails Received', $received, $this->learningCenterUrl()),
            $this->kpi('processed', 'Processed', $processed, $this->learningCenterUrl()),
            $this->kpi('needs_human', 'Needs Human', $needsHuman, $this->learningCenterUrl('needs_human'), highlight: $needsHuman > 0),
            $this->kpi('new_cases', 'New Cases', $newCases, $this->serviceCasesUrl()),
            $this->kpi('linked', 'Linked', $linked, $this->learningCenterUrl()),
            $this->kpi('ignored', 'Ignored', $ignored, $this->learningCenterUrl('automatic')),
            $this->kpi('failures', 'Failures', $failures, $this->failuresUrl(), highlight: $failures > 0),
        ];

        $pipeline = [
            ['key' => 'received', 'label' => 'Received', 'count' => $received],
            ['key' => 'filtered', 'label' => 'Filtered', 'count' => $ignored],
            ['key' => 'linked', 'label' => 'Linked', 'count' => $linked],
            ['key' => 'new_case', 'label' => 'New Case', 'count' => $newCases],
            ['key' => 'needs_human', 'label' => 'Needs Human', 'count' => $needsHuman],
            ['key' => 'completed', 'label' => 'Completed', 'count' => $linked + $newCases + $ignored],
        ];

        $caseCreation = $this->caseCreationBuckets($start, $end);
        $classification = $this->classificationBuckets($start, $end, $needsHuman);
        $assignment = $this->assignmentMetrics($start, $end, $needsHuman);
        $iraMemory = $this->iraMemoryMetrics($start, $end);
        $exceptions = $this->exceptions(
            needsHuman: $needsHuman,
            failedMessages: $failedMessages,
            fallbackCount: (int) ($assignment['fallback']['count'] ?? 0),
            stuckCount: $this->stuckEmailCount(),
        );
        $activity = $this->recentActivity();

        $overall = $this->overallStatus($needsHuman, $failures, $exceptions);

        return [
            'available' => true,
            'enabled' => true,
            'overall_status' => $overall->value,
            'generated_at' => $now->toIso8601String(),
            'kpis' => $kpis,
            'pipeline' => $pipeline,
            'case_creation' => $caseCreation,
            'classification' => $classification,
            'assignment' => $assignment,
            'ira_memory' => $iraMemory,
            'exceptions' => $exceptions,
            'activity' => $activity,
            'links' => $this->links(),
        ];
    }

    /**
     * @return array{key: string, label: string, count: int, url: ?string, highlight: bool}
     */
    private function kpi(string $key, string $label, int $count, ?string $url, bool $highlight = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'url' => $url,
            'highlight' => $highlight,
        ];
    }

    private function countReceived(Carbon $start, Carbon $end): int
    {
        return (int) IncomingEmailMessage::query()
            ->whereBetween('received_at', [$start, $end])
            ->count();
    }

    private function countProcessed(Carbon $start, Carbon $end): int
    {
        return (int) IncomingEmailMessage::query()
            ->whereBetween('processed_at', [$start, $end])
            ->count();
    }

    private function countIgnored(Carbon $start, Carbon $end): int
    {
        return (int) IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->whereBetween('processed_at', [$start, $end])
            ->count();
    }

    private function countFailedMessages(Carbon $start, Carbon $end): int
    {
        return (int) IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Failed)
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('processed_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end): void {
                        $inner->whereNull('processed_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->count();
    }

    private function countOpenGmailFailures(): int
    {
        if (! Schema::hasTable('gmail_sync_message_failures')) {
            return 0;
        }

        return (int) GmailSyncMessageFailure::query()->count();
    }

    private function countAudits(string $event, Carbon $start, Carbon $end): int
    {
        if (! Schema::hasTable('audit_logs')) {
            return 0;
        }

        return (int) AuditLog::query()
            ->where('event', $event)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * @return list<array{key: string, label: string, count: int, url: ?string}>
     */
    private function caseCreationBuckets(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $messageIds = AuditLog::query()
            ->where('event', 'incoming_email.promoted_to_service_case')
            ->whereBetween('created_at', [$start, $end])
            ->where('auditable_type', (new IncomingEmailMessage)->getMorphClass())
            ->pluck('auditable_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($messageIds === []) {
            return [];
        }

        $counts = IncomingEmailMessage::query()
            ->whereIn('id', $messageIds)
            ->selectRaw('classification, COUNT(*) as aggregate')
            ->groupBy('classification')
            ->pluck('aggregate', 'classification');

        $buckets = [
            'support' => 0,
            'sales' => 0,
            'refund' => 0,
            'vendor' => 0,
            'docs' => 0,
            'unknown' => 0,
        ];

        foreach ($counts as $classification => $count) {
            $stored = IncomingEmailClassification::tryFrom((string) $classification);
            $operator = IncomingEmailOperatorClassification::fromStored($stored);
            $key = match ($operator) {
                IncomingEmailOperatorClassification::Support => 'support',
                IncomingEmailOperatorClassification::Sales => 'sales',
                IncomingEmailOperatorClassification::Refund => 'refund',
                IncomingEmailOperatorClassification::Vendor => 'vendor',
                IncomingEmailOperatorClassification::Docs => 'docs',
                default => 'unknown',
            };
            $buckets[$key] += (int) $count;
        }

        $labels = [
            'support' => 'Support',
            'sales' => 'Sales',
            'refund' => 'Refund',
            'vendor' => 'Vendor',
            'docs' => 'Docs',
            'unknown' => 'Unknown',
        ];

        $rows = [];
        foreach ($buckets as $key => $count) {
            if ($count <= 0) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => $labels[$key],
                'count' => $count,
                'url' => $this->serviceCasesUrl(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, label: string, count: int, url: ?string}>
     */
    private function classificationBuckets(Carbon $start, Carbon $end, int $needsHuman): array
    {
        $counts = IncomingEmailMessage::query()
            ->whereBetween('processed_at', [$start, $end])
            ->whereNotNull('classification')
            ->selectRaw('classification, COUNT(*) as aggregate')
            ->groupBy('classification')
            ->pluck('aggregate', 'classification');

        $buckets = [
            'support' => 0,
            'sales' => 0,
            'refund' => 0,
            'promotion' => 0,
            'spam' => 0,
            'docs' => 0,
            'automatic' => 0,
        ];

        foreach ($counts as $classification => $count) {
            $stored = IncomingEmailClassification::tryFrom((string) $classification);
            $operator = IncomingEmailOperatorClassification::fromStored($stored);
            if ($operator === null) {
                continue;
            }
            $key = $operator->value;
            if (! array_key_exists($key, $buckets)) {
                continue;
            }
            $buckets[$key] += (int) $count;
        }

        $labels = [
            'support' => 'Support',
            'sales' => 'Sales',
            'refund' => 'Refund',
            'promotion' => 'Promotion',
            'spam' => 'Spam',
            'docs' => 'Docs',
            'automatic' => 'Completed Automatically',
        ];

        $queueMap = [
            'promotion' => 'promotional',
            'spam' => 'spam',
            'automatic' => 'automatic',
        ];

        $rows = [];
        foreach ($buckets as $key => $count) {
            if ($count <= 0) {
                continue;
            }
            $queue = $queueMap[$key] ?? 'needs_human';
            $rows[] = [
                'key' => $key,
                'label' => $labels[$key],
                'count' => $count,
                'url' => $this->learningCenterUrl($queue),
            ];
        }

        if ($needsHuman > 0) {
            $rows[] = [
                'key' => 'needs_human',
                'label' => 'Needs Human',
                'count' => $needsHuman,
                'url' => $this->learningCenterUrl('needs_human'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{key: string, label: string, count: int, url: ?string}>
     */
    private function assignmentMetrics(Carbon $start, Carbon $end, int $needsHuman): array
    {
        $assigned = (int) IncomingEmailMessage::query()
            ->whereIn('status', [
                IncomingEmailMessageStatus::NeedsReview,
                IncomingEmailMessageStatus::Failed,
            ])
            ->where(function ($query): void {
                $query->whereNotNull('learning_owner_user_id')
                    ->orWhereNotNull('suggested_assignee_user_id');
            })
            ->count();

        $pending = (int) IncomingEmailMessage::query()
            ->whereIn('status', [
                IncomingEmailMessageStatus::NeedsReview,
                IncomingEmailMessageStatus::Failed,
            ])
            ->whereNull('learning_owner_user_id')
            ->whereNull('suggested_assignee_user_id')
            ->count();

        $metrics = [];

        if ($needsHuman > 0 || $assigned > 0) {
            $metrics['assigned'] = [
                'key' => 'assigned',
                'label' => 'Assigned',
                'count' => $assigned,
                'url' => $this->learningCenterUrl('needs_human'),
            ];
        }

        if ($needsHuman > 0 || $pending > 0) {
            $metrics['pending'] = [
                'key' => 'pending',
                'label' => 'Pending',
                'count' => $pending,
                'url' => $this->learningCenterUrl('needs_human'),
            ];
        }

        $fallback = $this->countAudits('incoming_email.assignment_fallback', $start, $end);
        if ($fallback > 0) {
            $metrics['fallback'] = [
                'key' => 'fallback',
                'label' => 'Fallback',
                'count' => $fallback,
                'url' => $this->learningCenterUrl('needs_human'),
            ];
        }

        return $metrics;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function iraMemoryMetrics(Carbon $start, Carbon $end): ?array
    {
        if (! Schema::hasTable('ira_memories')) {
            return null;
        }

        $usedToday = (int) IncomingEmailMessage::query()
            ->whereNotNull('matched_ira_memory_id')
            ->whereBetween('processed_at', [$start, $end])
            ->count();

        $newMemories = (int) IraMemory::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $top = IraMemory::query()
            ->where('status', IraMemoryStatus::Active)
            ->orderByDesc('times_used')
            ->orderByDesc('id')
            ->first(['id', 'pattern_value', 'times_used', 'confidence']);

        $avgConfidence = IncomingEmailMessage::query()
            ->whereNotNull('matched_ira_memory_id')
            ->whereNotNull('ira_confidence')
            ->whereBetween('processed_at', [$start, $end])
            ->avg('ira_confidence');

        if ($usedToday === 0 && $newMemories === 0 && $top === null) {
            return null;
        }

        return [
            'used_today' => $usedToday,
            'new_memories' => $newMemories,
            'top_memory' => $top === null ? null : [
                'label' => (string) ($top->pattern_value ?: 'Memory #'.$top->id),
                'times_used' => (int) $top->times_used,
            ],
            'average_confidence' => $avgConfidence !== null ? (int) round((float) $avgConfidence) : null,
            'url' => Route::has('admin.ira-memory.index')
                ? route('admin.ira-memory.index')
                : $this->learningCenterUrl('needs_human'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $exceptions
     * @return list<array{key: string, label: string, detail: string, url: ?string, severity: string}>
     */
    private function exceptions(int $needsHuman, int $failedMessages, int $fallbackCount, int $stuckCount): array
    {
        $items = [];

        if ($needsHuman > 0) {
            $items[] = [
                'key' => 'needs_human',
                'label' => 'Needs Human',
                'detail' => $needsHuman.' email(s) waiting for operator action.',
                'url' => $this->learningCenterUrl('needs_human'),
                'severity' => $needsHuman >= 10 ? 'critical' : 'warning',
            ];
        }

        if ($failedMessages > 0) {
            $items[] = [
                'key' => 'processing_failure',
                'label' => 'Processing Failure',
                'detail' => $failedMessages.' message(s) failed processing today.',
                'url' => $this->learningCenterUrl('needs_human'),
                'severity' => 'critical',
            ];
        }

        if ($fallbackCount > 0) {
            $items[] = [
                'key' => 'fallback_used',
                'label' => 'Fallback Used',
                'detail' => $fallbackCount.' assignment fallback event(s) today.',
                'url' => $this->learningCenterUrl('needs_human'),
                'severity' => 'warning',
            ];
        }

        if ($stuckCount > 0) {
            $items[] = [
                'key' => 'stuck_emails',
                'label' => 'Stuck Emails',
                'detail' => $stuckCount.' message(s) stuck in received/processing over 1 hour.',
                'url' => $this->learningCenterUrl(),
                'severity' => 'critical',
            ];
        }

        $gmailWidget = $this->gmailHealth->widget();
        $lastSync = $gmailWidget['last_successful_sync_at'] ?? null;
        $syncedAt = null;
        if ($lastSync instanceof Carbon) {
            $syncedAt = $lastSync;
        } elseif (is_string($lastSync) && $lastSync !== '') {
            try {
                $syncedAt = Carbon::parse($lastSync);
            } catch (\Throwable) {
                $syncedAt = null;
            }
        }

        if ($syncedAt !== null && $syncedAt->lt(now()->subMinutes(15))) {
            $items[] = [
                'key' => 'gmail_sync_delayed',
                'label' => 'Gmail Sync Delayed',
                'detail' => 'Last successful sync '.$syncedAt->diffForHumans().'.',
                'url' => Route::has('admin.gmail.logs') ? route('admin.gmail.logs') : null,
                'severity' => 'warning',
            ];
        } elseif ($syncedAt === null && ($gmailWidget['status'] ?? null) === 'critical') {
            $items[] = [
                'key' => 'gmail_sync_delayed',
                'label' => 'Gmail Sync Delayed',
                'detail' => (string) ($gmailWidget['detail'] ?? 'Gmail sync needs attention.'),
                'url' => Route::has('admin.gmail.logs') ? route('admin.gmail.logs') : null,
                'severity' => 'critical',
            ];
        }

        return $items;
    }

    private function stuckEmailCount(): int
    {
        return (int) IncomingEmailMessage::query()
            ->whereIn('status', [
                IncomingEmailMessageStatus::Received,
                IncomingEmailMessageStatus::Processing,
            ])
            ->where('created_at', '<', now()->subHour())
            ->count();
    }

    /**
     * @return list<array{at: string, label: string, detail: string, url: ?string}>
     */
    private function recentActivity(): array
    {
        $events = [];

        if (Schema::hasTable('audit_logs')) {
            $audits = AuditLog::query()
                ->whereIn('event', self::ACTIVITY_EVENTS)
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'event', 'auditable_type', 'auditable_id', 'new_values', 'created_at']);

            foreach ($audits as $audit) {
                $events[] = [
                    'at' => $audit->created_at?->toIso8601String() ?? '',
                    'sort' => $audit->created_at?->getTimestamp() ?? 0,
                    'label' => $this->activityLabel((string) $audit->event),
                    'detail' => $this->activityDetail($audit),
                    'url' => $this->activityUrl($audit),
                ];
            }
        }

        $recentMessages = IncomingEmailMessage::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'from_email', 'subject', 'received_at', 'created_at', 'status']);

        foreach ($recentMessages as $message) {
            $at = $message->received_at ?? $message->created_at;
            $queue = match ($message->status) {
                IncomingEmailMessageStatus::Ignored => 'automatic',
                IncomingEmailMessageStatus::NeedsReview, IncomingEmailMessageStatus::Failed => 'needs_human',
                default => null,
            };
            $events[] = [
                'at' => $at?->toIso8601String() ?? '',
                'sort' => $at?->getTimestamp() ?? 0,
                'label' => 'Email received',
                'detail' => trim(($message->from_email ?? '').' · '.($message->subject ?: 'No subject')),
                'url' => $this->learningCenterUrl($queue),
            ];
        }

        usort($events, static fn (array $a, array $b): int => ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0));

        return array_map(static function (array $event): array {
            unset($event['sort']);

            return $event;
        }, array_slice($events, 0, 20));
    }

    private function activityLabel(string $event): string
    {
        return match ($event) {
            'incoming_email.linked' => 'Linked',
            'incoming_email.promoted_to_service_case' => 'Case created',
            'incoming_email.ignored' => 'Ignored',
            'incoming_email.needs_review' => 'Needs Human',
            'incoming_email.processing_failed' => 'Processing failed',
            'incoming_email.routed' => 'Routed',
            'incoming_email.learning_action' => 'Learning action',
            'incoming_email.learning_rule_applied' => 'Memory matched',
            'incoming_email.assignment_fallback' => 'Assignment fallback',
            'incoming_email.disposition' => 'Disposed',
            default => str_replace(['incoming_email.', '_'], ['', ' '], $event),
        };
    }

    private function activityDetail(AuditLog $audit): string
    {
        $values = is_array($audit->new_values) ? $audit->new_values : [];
        $bits = [];
        foreach (['classification', 'reason', 'decision_type', 'decision_value', 'route'] as $key) {
            if (! empty($values[$key]) && is_scalar($values[$key])) {
                $bits[] = (string) $values[$key];
            }
        }

        if ($bits !== []) {
            return implode(' · ', $bits);
        }

        return 'Message #'.$audit->auditable_id;
    }

    private function activityUrl(AuditLog $audit): ?string
    {
        if ($audit->event === 'incoming_email.promoted_to_service_case') {
            $incidentId = data_get($audit->new_values, 'incident_id');
            if (is_numeric($incidentId) && Route::has('dashboard')) {
                return route('dashboard', ['open_customer_360' => (int) $incidentId]);
            }
        }

        return $this->learningCenterUrl(
            in_array($audit->event, ['incoming_email.ignored'], true) ? 'automatic' : 'needs_human',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $exceptions
     */
    private function overallStatus(int $needsHuman, int $failures, array $exceptions): PlatformHealthStatus
    {
        foreach ($exceptions as $exception) {
            if (($exception['severity'] ?? '') === 'critical') {
                return PlatformHealthStatus::Critical;
            }
        }

        if ($failures > 0 || $needsHuman > 5) {
            return PlatformHealthStatus::Warning;
        }

        if ($exceptions !== []) {
            return PlatformHealthStatus::Warning;
        }

        return PlatformHealthStatus::Healthy;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function links(): array
    {
        $links = [];

        if (Route::has('admin.incoming-emails.index')) {
            $links[] = [
                'label' => 'Email Intake Queues',
                'url' => route('admin.incoming-emails.index', ['queue' => 'needs_human']),
            ];
        }

        if (Route::has('admin.gmail.logs')) {
            $links[] = [
                'label' => 'Gmail Sync Logs',
                'url' => route('admin.gmail.logs'),
            ];
        }

        if (Route::has('admin.gmail.failed-messages')) {
            $links[] = [
                'label' => 'Gmail Failed Messages',
                'url' => route('admin.gmail.failed-messages'),
            ];
        }

        $links[] = [
            'label' => 'Integration Health (Gmail)',
            'url' => '#platform-zone-integration_health',
        ];

        return $links;
    }

    private function learningCenterUrl(?string $queue = null): ?string
    {
        if (! Route::has('admin.incoming-emails.index')) {
            return null;
        }

        $params = [];
        if ($queue !== null && $queue !== '') {
            $params['queue'] = $queue;
        }

        return route('admin.incoming-emails.index', $params);
    }

    private function serviceCasesUrl(): ?string
    {
        if (Route::has('dashboard')) {
            return route('dashboard', ['workspace' => 'active_cases']);
        }

        if (Route::has('incidents.index')) {
            return route('incidents.index', ['status' => 'active']);
        }

        return null;
    }

    private function failuresUrl(): ?string
    {
        if (Route::has('admin.gmail.failed-messages')) {
            return route('admin.gmail.failed-messages');
        }

        return $this->learningCenterUrl('needs_human');
    }
}
