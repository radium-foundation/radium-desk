<?php

namespace App\Services\Repairs\Appointments;

use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Support\Repair\Contracts\RepairVerifierInterface;
use App\Support\Repair\Data\RepairVerificationReport;
use App\Support\Repair\Enums\RepairItemOutcome;
use App\Support\Repair\Models\SystemRepairBatch;
use App\Support\Repair\Models\SystemRepairItem;

class ClosedAppointmentWorkflowVerifier implements RepairVerifierInterface
{
    public function verifyBatch(SystemRepairBatch $batch): RepairVerificationReport
    {
        $items = [];
        $passed = 0;
        $failed = 0;

        foreach ($batch->items()->orderBy('id')->cursor() as $item) {
            $result = $this->verifyItem($item);
            $items[] = [
                'subject_key' => (string) $item->subject_key,
                'ok' => $result['ok'],
                'message' => $result['message'],
            ];

            if ($result['ok']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return new RepairVerificationReport(
            ok: $failed === 0,
            checked: $passed + $failed,
            passed: $passed,
            failed: $failed,
            items: $items,
            summary: $failed === 0
                ? 'All repaired items verified.'
                : sprintf('%d item(s) failed verification.', $failed),
        );
    }

    public function verifyItem(SystemRepairItem $item): array
    {
        if (in_array($item->outcome, [
            RepairItemOutcome::WouldRepair,
            RepairItemOutcome::WouldCleanup,
            RepairItemOutcome::WouldSkip,
            RepairItemOutcome::Skipped,
        ], true)) {
            return ['ok' => true, 'message' => 'Preview/skip item — nothing to verify.'];
        }

        $incident = Incident::query()->find($item->subject_id);
        if ($incident === null) {
            return ['ok' => false, 'message' => 'Incident missing.'];
        }

        if ($item->action === 'full' && $item->outcome === RepairItemOutcome::Repaired) {
            if ($incident->status === IncidentStatus::Closed) {
                return ['ok' => false, 'message' => 'Expected case to be reopened.'];
            }

            return ['ok' => true, 'message' => 'Case is open after full repair.'];
        }

        if ($item->action === 'cleanup' && $item->outcome === RepairItemOutcome::CleanedUp) {
            $stillScheduled = SupportAppointment::query()
                ->where('incident_id', $incident->id)
                ->where('status', SupportAppointmentStatus::Scheduled)
                ->exists();

            if ($stillScheduled) {
                return ['ok' => false, 'message' => 'Scheduled appointment still present.'];
            }

            if ($incident->status !== IncidentStatus::Closed) {
                return ['ok' => false, 'message' => 'Cleanup should leave case closed.'];
            }

            return ['ok' => true, 'message' => 'Cleanup verified.'];
        }

        return ['ok' => true, 'message' => 'No verification rule for outcome '.$item->outcome->value];
    }
}
