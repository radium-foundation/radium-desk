<?php

namespace App\Services\Inquiry;

use App\Data\InquirySpamCleanupSummary;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\Remark;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\Bonvoice\BonvoiceMissedCallRecoveryService;
use App\Services\RemarkService;
use App\Services\ServiceCaseStatusService;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InquirySpamCleanupService
{
    public const ARCHIVE_REMARK = 'Archived automatically: IVR no-input spam enquiry';

    public const EVENT_ARCHIVED = 'inquiry_spam.archived';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly RemarkService $remarkService,
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
    ) {}

    public function cleanup(bool $dryRun = false, ?Carbon $before = null): InquirySpamCleanupSummary
    {
        $candidates = $this->spamCandidates($before);
        $casesClosed = 0;
        $skipped = 0;
        $wouldClose = 0;
        /** @var list<string> $references */
        $references = [];
        /** @var array<string, int> $skipReasons */
        $skipReasons = [];

        foreach ($candidates as $incident) {
            $skipReason = $this->skipReason($incident);

            if ($skipReason !== null) {
                $skipped++;
                $this->recordSkipReason($skipReasons, $skipReason);

                continue;
            }

            $references[] = $incident->display_reference;

            if ($dryRun) {
                $wouldClose++;

                continue;
            }

            $failureReason = $this->archiveCase($incident);

            if ($failureReason === null) {
                $casesClosed++;
            } else {
                $skipped++;
                $this->recordSkipReason($skipReasons, $failureReason);
            }
        }

        return new InquirySpamCleanupSummary(
            totalFound: $candidates->count(),
            casesClosed: $casesClosed,
            skipped: $skipped,
            wouldClose: $wouldClose,
            references: $references,
            skipReasons: $skipReasons,
        );
    }

    /**
     * @return Collection<int, Incident>
     */
    public function spamCandidates(?Carbon $before = null): Collection
    {
        return Incident::query()
            ->where('status', '!=', IncidentStatus::Closed)
            ->where('source', IncidentSource::Call)
            ->whereIn('category', [
                BonvoiceMissedCallRecoveryService::CATEGORY,
                'General Enquiry',
            ])
            ->whereHas('order', fn ($query) => $query->where('order_id', 'like', 'INQ-%'))
            ->when($before !== null, fn ($query) => $query->where('created_at', '<', $before))
            ->with(['order', 'bonvoiceCallLinks.bonvoiceCallEvent', 'remarks.user'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Incident $incident): bool => $this->isSpamCandidate($incident))
            ->values();
    }

    public function isSpamCandidate(Incident $incident): bool
    {
        if ($incident->status === IncidentStatus::Closed) {
            return false;
        }

        if ($incident->source !== IncidentSource::Call) {
            return false;
        }

        if (! in_array($incident->category, [
            BonvoiceMissedCallRecoveryService::CATEGORY,
            'General Enquiry',
        ], true)) {
            return false;
        }

        $order = $incident->order;

        if ($order === null || ! $order->isInquiryOrder()) {
            return false;
        }

        return $this->hasSpamBonvoiceEvidence($incident);
    }

    public function skipReason(Incident $incident): ?string
    {
        if (! $this->isSpamCandidate($incident)) {
            return 'not eligible';
        }

        if ($this->hasHumanRemark($incident)) {
            return 'has human remarks';
        }

        if ($this->hasManualReassignment($incident)) {
            return 'manual reassignment';
        }

        return null;
    }

    /**
     * Human handling means a real teammate left meaningful remark text.
     * Blank/system/Ira placeholder remarks do not protect a case from archive.
     */
    public function hasHumanRemark(Incident $incident): bool
    {
        $incident->loadMissing('remarks.user');

        foreach ($incident->remarks as $remark) {
            if ($this->isHumanRemark($remark)) {
                return true;
            }
        }

        return false;
    }

    private function isHumanRemark(Remark $remark): bool
    {
        $body = trim((string) $remark->body);

        if ($body === '' || $this->isIgnoredSystemRemarkBody($body)) {
            return false;
        }

        $creator = $remark->user;

        if ($creator === null || $this->automationIdentity->isAutomationActor($creator)) {
            return false;
        }

        return true;
    }

    private function isIgnoredSystemRemarkBody(string $body): bool
    {
        $normalized = mb_strtolower(trim($body));

        if ($normalized === '') {
            return true;
        }

        if ($normalized === mb_strtolower(self::ARCHIVE_REMARK)) {
            return true;
        }

        // Ira / automation placeholder noise seen in production spam cases.
        $placeholders = [
            'ira',
            'ira ai',
            'automation',
            'system',
            '-',
            'n/a',
            'na',
            'none',
        ];

        return in_array($normalized, $placeholders, true);
    }

    private function archiveCase(Incident $incident): ?string
    {
        try {
            $actor = $this->automationIdentity->systemUser();
        } catch (ModelNotFoundException) {
            return 'missing actor';
        }

        try {
            DB::transaction(function () use ($incident, $actor): void {
                $this->remarkService->createForRemarkable(
                    remarkable: $incident,
                    actor: $actor,
                    body: self::ARCHIVE_REMARK,
                );

                $this->serviceCaseStatusService->updateStatus($incident, IncidentStatus::Closed, $actor);

                $this->auditLogService->log(
                    userId: $actor->id,
                    event: self::EVENT_ARCHIVED,
                    auditable: $incident->fresh(),
                    oldValues: [
                        'status' => $incident->status->value,
                    ],
                    newValues: [
                        'status' => IncidentStatus::Closed->value,
                        'resolution_reason' => ServiceCaseCloseExceptionReason::DuplicateServiceCase->value,
                        'resolution_reason_label' => ServiceCaseCloseExceptionReason::DuplicateServiceCase->label(),
                        'archive_reason' => 'noinput_spam_enquiry',
                    ],
                );
            });

            return null;
        } catch (ValidationException) {
            return 'close validation failed';
        } catch (QueryException) {
            return 'database error';
        } catch (\Throwable) {
            return 'archive failed';
        }
    }

    private function hasSpamBonvoiceEvidence(Incident $incident): bool
    {
        $incident->loadMissing('bonvoiceCallLinks.bonvoiceCallEvent');

        if ($incident->bonvoiceCallLinks->isEmpty()) {
            return false;
        }

        $hasCustomerInteraction = false;
        $hasNoInput = false;
        $hasNoInteraction = false;

        foreach ($incident->bonvoiceCallLinks as $link) {
            $event = $link->bonvoiceCallEvent;

            if (! $event instanceof BonvoiceCallEvent) {
                continue;
            }

            if (BonvoiceCallStatuses::normalize($event->status) === 'NOINPUT') {
                $hasNoInput = true;
            }

            if ($this->hasCustomerInteraction($event)) {
                $hasCustomerInteraction = true;
            } else {
                $hasNoInteraction = true;
            }
        }

        if ($hasCustomerInteraction) {
            return false;
        }

        return $hasNoInput || $hasNoInteraction;
    }

    private function hasCustomerInteraction(BonvoiceCallEvent $event): bool
    {
        if (BonvoiceCallStatuses::normalize($event->status) === 'NOINPUT') {
            return false;
        }

        return $this->hasNonEmptyCallbackParams($event->callback_params);
    }

    private function hasNonEmptyCallbackParams(mixed $callbackParams): bool
    {
        if (! is_array($callbackParams) || $callbackParams === []) {
            return false;
        }

        foreach ($callbackParams as $value) {
            if (is_array($value)) {
                if ($this->hasNonEmptyCallbackParams($value)) {
                    return true;
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                return true;
            }

            if (is_numeric($value) || is_bool($value)) {
                return true;
            }
        }

        return false;
    }

    private function hasManualReassignment(Incident $incident): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.reassigned')
            ->get()
            ->contains(fn (AuditLog $log): bool => ($log->new_values['override_reason'] ?? null) === 'manual_reassign');
    }

    /**
     * @param  array<string, int>  $skipReasons
     */
    private function recordSkipReason(array &$skipReasons, string $reason): void
    {
        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
    }
}
