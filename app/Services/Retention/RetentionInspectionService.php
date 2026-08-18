<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionCategorySummary;
use App\Data\Retention\RetentionInspectionSummary;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\OutboxEventStatus;
use App\Models\AuditLog;
use App\Models\BonvoiceWebhookLog;
use App\Models\CashfreeWebhookLog;
use App\Models\IncomingEmailMessage;
use App\Models\InteraktWebhookLog;
use App\Models\IraNotification;
use App\Models\OutboxEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionInspectionService
{
    /**
     * Read-only inspection. Never writes, updates, or deletes.
     */
    public function inspect(?Carbon $at = null): RetentionInspectionSummary
    {
        $at ??= now();

        $categories = array_values(array_filter([
            $this->inspectCompletedOutbox($at),
            $this->inspectExpiredCache($at),
            $this->inspectWebhookLogs(CashfreeWebhookLog::class, 'cashfree_webhook_logs', 'Cashfree webhook logs', $at),
            $this->inspectWebhookLogs(InteraktWebhookLog::class, 'interakt_webhook_logs', 'Interakt webhook logs', $at),
            $this->inspectWebhookLogs(BonvoiceWebhookLog::class, 'bonvoice_webhook_logs', 'Bonvoice webhook logs', $at),
            $this->inspectNotifications($at),
            $this->inspectIraNotifications($at),
            $this->inspectBusinessAuditLogs($at),
            $this->inspectIgnoredEmailMessages($at),
        ]));

        $totalCandidates = array_sum(array_map(
            static fn (RetentionCategorySummary $category): int => $category->candidateCount,
            $categories,
        ));

        return new RetentionInspectionSummary(
            inspectedAt: $at,
            categories: $categories,
            totalCandidates: $totalCandidates,
        );
    }

    private function inspectCompletedOutbox(Carbon $at): ?RetentionCategorySummary
    {
        if (! Schema::hasTable('outbox_events')) {
            return null;
        }

        $days = (int) config('retention.completed_outbox_days', 14);
        $cutoff = $at->copy()->subDays($days);

        return new RetentionCategorySummary(
            key: 'completed_outbox',
            table: 'outbox_events',
            label: 'Completed outbox events',
            retentionDays: $days,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: OutboxEvent::query()
                ->where('status', OutboxEventStatus::Completed)
                ->where('processed_at', '<', $cutoff)
                ->count(),
            tableTotalCount: OutboxEvent::query()->count(),
        );
    }

    private function inspectExpiredCache(Carbon $at): ?RetentionCategorySummary
    {
        if (! Schema::hasTable('cache') || ! (bool) config('retention.expired_cache_immediate', true)) {
            return null;
        }

        return new RetentionCategorySummary(
            key: 'expired_cache',
            table: 'cache',
            label: 'Expired cache rows',
            retentionDays: 0,
            cutoffAt: $at->toIso8601String(),
            candidateCount: (int) DB::table('cache')
                ->where('expiration', '<', $at->getTimestamp())
                ->count(),
            tableTotalCount: (int) DB::table('cache')->count(),
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    private function inspectWebhookLogs(string $modelClass, string $table, string $label, Carbon $at): ?RetentionCategorySummary
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $days = (int) config('retention.webhook_logs_days', 90);
        $cutoff = $at->copy()->subDays($days);

        return new RetentionCategorySummary(
            key: $table,
            table: $table,
            label: $label,
            retentionDays: $days,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: $modelClass::query()
                ->where('received_at', '<', $cutoff)
                ->count(),
            tableTotalCount: $modelClass::query()->count(),
        );
    }

    private function inspectNotifications(Carbon $at): ?RetentionCategorySummary
    {
        if (! Schema::hasTable('notifications')) {
            return null;
        }

        $days = (int) config('retention.notifications_days', 90);
        $cutoff = $at->copy()->subDays($days);

        return new RetentionCategorySummary(
            key: 'notifications',
            table: 'notifications',
            label: 'In-app notifications',
            retentionDays: $days,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: (int) DB::table('notifications')
                ->where('created_at', '<', $cutoff)
                ->count(),
            tableTotalCount: (int) DB::table('notifications')->count(),
        );
    }

    private function inspectIraNotifications(Carbon $at): ?RetentionCategorySummary
    {
        if (! Schema::hasTable('ira_notifications')) {
            return null;
        }

        $days = (int) config('retention.notifications_days', 90);
        $cutoff = $at->copy()->subDays($days);

        return new RetentionCategorySummary(
            key: 'ira_notifications',
            table: 'ira_notifications',
            label: 'Ira notifications',
            retentionDays: $days,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: IraNotification::query()
                ->where('created_at', '<', $cutoff)
                ->count(),
            tableTotalCount: IraNotification::query()->count(),
        );
    }

    private function inspectBusinessAuditLogs(Carbon $at): ?RetentionCategorySummary
    {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $days = (int) config('retention.business_audit_days', 365);
        $cutoff = $at->copy()->subDays($days);

        return new RetentionCategorySummary(
            key: 'business_audit',
            table: 'audit_logs',
            label: 'Business audit logs (non email)',
            retentionDays: $days,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: AuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->where('event', 'not like', 'incoming_email.%')
                ->count(),
            tableTotalCount: AuditLog::query()->count(),
        );
    }

    private function inspectIgnoredEmailMessages(Carbon $at): ?RetentionCategorySummary
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return null;
        }

        $days = (int) config('retention.ignored_email_days', 90);
        $cutoff = $at->copy()->subDays($days);

        return new RetentionCategorySummary(
            key: 'ignored_email',
            table: 'incoming_email_messages',
            label: 'Ignored incoming email rows',
            retentionDays: $days,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: IncomingEmailMessage::query()
                ->where('status', IncomingEmailMessageStatus::Ignored)
                ->where('created_at', '<', $cutoff)
                ->count(),
            tableTotalCount: IncomingEmailMessage::query()->count(),
        );
    }
}
