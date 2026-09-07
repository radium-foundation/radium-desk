<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

#[Signature('desk:fulfil-hardware {id? : Exactly one RDE source id or hardware_fulfilment.id} {--dry-run : Inspect gates without writing} {--step=status : status|ingest|ready|allocate|invoice|ship|awb|shipped|sync} {--through= : Advance this one order through a named step} {--serials= : Comma-separated serials; required to allocate} {--claimed-branch= : Optional operator branch claim} {--payload= : JSON file for isolated ingest of this id only} {--actor= : Active Desk user id for allocation/invoice} {--live-shipping : Use HttpShiprocketGateway for this process only} {--force-callback : Send the pending Box callback for this fulfilment only}')]
#[Description('Isolated one-order hardware fulfilment. Never discovers or processes a batch.')]
class FulfilHardwareCommand extends Command
{
    public function handle(HardwareFulfilmentIsolatedWorkflowService $workflow): int
    {
        $rawId = $this->argument('id');
        if (! is_string($rawId) || trim($rawId) === '') {
            $this->error('desk:fulfil-hardware requires exactly one explicit order or fulfilment id.');

            return self::FAILURE;
        }

        try {
            if ((bool) $this->option('live-shipping')) {
                HardwareFulfilmentIsolatedWorkflowService::prepareLiveShipping();
            }

            $result = $workflow->run(
                identifier: $rawId,
                step: (string) $this->option('step'),
                dryRun: (bool) $this->option('dry-run'),
                through: $this->stringOption('through'),
                serials: $this->serials(),
                claimedBranch: $this->stringOption('claimed-branch'),
                payload: $this->payload(),
                actor: $this->actor(),
                liveShipping: (bool) $this->option('live-shipping'),
                forceCallback: (bool) $this->option('force-callback'),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (JsonException $exception) {
            $this->error('Payload file is not valid JSON.');

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function serials(): array
    {
        $raw = $this->stringOption('serials');
        if ($raw === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $serial): string => trim($serial),
            explode(',', $raw),
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(): ?array
    {
        $path = $this->stringOption('payload');
        if ($path === null) {
            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'payload' => 'Payload file is missing or unreadable.',
            ]);
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw ValidationException::withMessages([
                'payload' => 'Payload file must contain exactly one JSON object.',
            ]);
        }

        return $decoded;
    }

    private function actor(): ?User
    {
        $raw = $this->stringOption('actor');
        if ($raw === null) {
            return null;
        }

        if (! ctype_digit($raw)) {
            throw ValidationException::withMessages([
                'actor' => '--actor must be an active Desk user id.',
            ]);
        }

        $user = User::query()->whereKey((int) $raw)->where('is_active', true)->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'actor' => 'Isolated fulfilment actor was not found or is inactive.',
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
