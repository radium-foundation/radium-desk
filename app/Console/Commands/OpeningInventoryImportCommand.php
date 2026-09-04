<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Inventory\Opening\OpeningInventoryImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('inventory:opening-import {path : Absolute path to the agreed opening-inventory .xlsx} {--apply : Persist stock after a clean preview. Omit for preview-only.} {--actor= : User id or email recorded on movements}')]
#[Description('Preview or apply a physical-count opening inventory workbook. Does not invent quantities or create branches.')]
class OpeningInventoryImportCommand extends Command
{
    public function __construct(
        private readonly OpeningInventoryImportService $imports,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $apply = (bool) $this->option('apply');

        try {
            $actor = $this->resolveActor();
            $result = $apply
                ? $this->imports->apply($path, $actor)
                : $this->imports->preview($path, $actor);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info($apply
            ? ($result->alreadyApplied
                ? 'This workbook was already applied. Stock was not changed again.'
                : 'Opening inventory applied.')
            : 'Preview only. Stock was not changed.');
        $this->line('Checksum: '.$result->batch->source_checksum);
        $this->line('Opening rows: '.$result->openingRows);
        $this->line('Valid: '.$result->validRows);
        $this->line('Invalid: '.$result->invalidRows);
        $this->line('SKU Master rows: '.$result->skuRows);
        $this->line('Can apply: '.($result->canApply ? 'yes' : 'no'));
        $this->line('Reconciliation (valid rows): available serials '.$result->reconciliation->availableSerials
            .', damaged serials '.$result->reconciliation->damagedSerials
            .', quantity units '.$result->reconciliation->quantityUnits);
        if ($apply) {
            $this->line('SKUs created: '.$result->skusCreated);
            $this->line('Variants created: '.$result->variantsCreated);
            $this->line('Rows applied: '.$result->rowsApplied);
        }

        foreach ($result->issues as $issue) {
            $prefix = $issue->blocking ? 'ERROR' : 'WARN';
            $location = $issue->rowNumber > 0 ? $issue->sheet.' row '.$issue->rowNumber : $issue->sheet;
            $this->line("{$prefix} [{$issue->code}] {$location}: {$issue->message}");
        }

        return $result->canApply || $result->alreadyApplied ? self::SUCCESS : self::FAILURE;
    }

    private function resolveActor(): User
    {
        $actor = $this->option('actor');
        if ($actor === null || $actor === '') {
            throw ValidationException::withMessages([
                'actor' => 'Pass --actor= with a user id or email. Import will not invent an operator.',
            ]);
        }

        $query = User::query();
        $user = is_numeric($actor)
            ? $query->find((int) $actor)
            : $query->where('email', $actor)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'actor' => 'Actor was not found.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'actor' => 'Actor is inactive. Import will not invent an operator.',
            ]);
        }

        if (! $user->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPENING_IMPORT)) {
            throw ValidationException::withMessages([
                'actor' => 'Actor does not have inventory.opening.import. Hardware and agents cannot import opening stock.',
            ]);
        }

        return $user;
    }
}
