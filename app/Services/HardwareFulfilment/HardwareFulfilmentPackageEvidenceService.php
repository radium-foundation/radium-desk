<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentPackageEvidenceService
{
    public function attach(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentPackageEvidenceKind $kind,
        UploadedFile $file,
        ?User $actor = null,
    ): HardwareFulfilmentPackageEvidence {
        $this->assertNotFrozen($fulfilment);
        $this->assertKindAllowed($fulfilment, $kind);

        return DB::transaction(function () use ($fulfilment, $kind, $file, $actor): HardwareFulfilmentPackageEvidence {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNotFrozen($locked);
            $this->assertKindAllowed($locked, $kind);

            $existing = HardwareFulfilmentPackageEvidence::query()
                ->where('hardware_fulfilment_id', $locked->id)
                ->where('kind', $kind)
                ->lockForUpdate()
                ->first();

            $directory = 'private/hardware-fulfilment-evidence/'.$locked->id;
            $stored = $file->store($directory, 'local');
            if (! is_string($stored) || $stored === '') {
                throw ValidationException::withMessages([
                    'photo' => 'The package photo could not be stored.',
                ]);
            }

            if ($existing !== null && filled($existing->path) && $existing->path !== $stored) {
                Storage::disk($existing->disk ?: 'local')->delete($existing->path);
            }

            $evidence = $existing ?? new HardwareFulfilmentPackageEvidence([
                'hardware_fulfilment_id' => $locked->id,
                'kind' => $kind,
            ]);

            $evidence->forceFill([
                'disk' => 'local',
                'path' => $stored,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize() ?: null,
                'uploaded_by_user_id' => $actor?->id,
                'uploaded_at' => now(),
            ])->save();

            HardwareFulfilmentEvent::query()->create([
                'hardware_fulfilment_id' => $locked->id,
                'from_state' => $locked->state,
                'to_state' => $locked->state,
                'actor_type' => $actor !== null ? 'user' : 'system',
                'actor_id' => $actor?->id,
                'payload' => [
                    'reason' => 'hardware_package_evidence_recorded',
                    'kind' => $kind->value,
                    'result' => $existing === null ? 'created' : 'replaced',
                ],
                'created_at' => now(),
            ]);

            return $evidence->fresh() ?? $evidence;
        });
    }

    public function latest(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentPackageEvidenceKind $kind,
    ): ?HardwareFulfilmentPackageEvidence {
        return $fulfilment->packageEvidences
            ->first(fn (HardwareFulfilmentPackageEvidence $row): bool => $row->kind === $kind)
            ?? HardwareFulfilmentPackageEvidence::query()
                ->where('hardware_fulfilment_id', $fulfilment->id)
                ->where('kind', $kind)
                ->first();
    }

    private function assertKindAllowed(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentPackageEvidenceKind $kind,
    ): void {
        if ($kind === HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel) {
            return;
        }

        if ($fulfilment->state !== HardwareFulfilmentState::AwbAssigned
            && ($fulfilment->state?->rank() ?? -1) < HardwareFulfilmentState::AwbAssigned->rank()) {
            throw ValidationException::withMessages([
                'photo' => 'The label-applied package photo can be recorded only after an AWB exists.',
            ]);
        }
    }

    private function assertNotFrozen(HardwareFulfilment $fulfilment): void
    {
        if (HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be shipped.',
            ]);
        }
    }
}
