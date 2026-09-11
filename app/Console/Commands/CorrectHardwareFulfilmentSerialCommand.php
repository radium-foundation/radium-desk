<?php

namespace App\Console\Commands;

use App\Models\HardwareFulfilment;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentSerialCorrectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('desk:correct-hardware-serial {id : RDE/RIN source id or hardware_fulfilment.id} {--from= : Currently allocated incorrect serial} {--to= : Correct physical serial} {--actor= : Active Desk user id} {--reason= : Optional audit reason} {--dry-run : Inspect gates without writing}')]
#[Description('Correct an allocated hardware fulfilment serial after invoice issuance when no IRN has been submitted.')]
class CorrectHardwareFulfilmentSerialCommand extends Command
{
    public function handle(HardwareFulfilmentSerialCorrectionService $corrections): int
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));

        if ($from === '' || $to === '') {
            $this->error('--from and --to are required.');

            return self::FAILURE;
        }

        try {
            $fulfilment = $this->resolveFulfilment((string) $this->argument('id'));

            if ((bool) $this->option('dry-run')) {
                $result = $corrections->preview($fulfilment, $from, $to);
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $actor = $this->actor();
            $updated = $corrections->correct(
                $fulfilment,
                $from,
                $to,
                $actor,
                $this->stringOption('reason'),
            );

            $this->line(json_encode([
                'ok' => true,
                'source_id' => $updated->source_id,
                'hardware_fulfilment_id' => $updated->id,
                'state' => $updated->state->value,
                'serials' => $updated->serials->pluck('serial_number')->values()->all(),
                'invoice_serials' => $updated->metadata['invoice_serials'] ?? [],
                'awb' => $updated->awb,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveFulfilment(string $identifier): HardwareFulfilment
    {
        $trimmed = strtoupper(trim($identifier));

        if (ctype_digit($trimmed)) {
            $fulfilment = HardwareFulfilment::query()->find((int) $trimmed);
            if ($fulfilment !== null) {
                return $fulfilment;
            }
        }

        $fulfilment = HardwareFulfilment::query()->where('source_id', $trimmed)->first();
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'fulfilment' => "Hardware fulfilment {$trimmed} was not found.",
            ]);
        }

        return $fulfilment;
    }

    private function actor(): User
    {
        $raw = $this->stringOption('actor');
        if ($raw === null) {
            throw ValidationException::withMessages([
                'actor' => '--actor is required with an active Desk user id.',
            ]);
        }

        if (! ctype_digit($raw)) {
            throw ValidationException::withMessages([
                'actor' => '--actor must be an active Desk user id.',
            ]);
        }

        $user = User::query()->whereKey((int) $raw)->where('is_active', true)->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'actor' => 'Serial correction actor was not found or is inactive.',
            ]);
        }

        return $user;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
