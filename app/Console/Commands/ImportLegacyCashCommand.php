<?php

namespace App\Console\Commands;

use App\Models\FinanceCashAccount;
use App\Models\FinanceJournal;
use App\Models\User;
use App\Services\Finance\LegacyCashImportService;
use App\Services\Finance\LegacyCashSourceReader;
use App\Services\Finance\OpeningBalanceService;
use App\Support\Finance\LegacyCashContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('finance:import-legacy-cash {--apply : Persist Legacy Cash rows. Omit for dry-run.} {--post-opening : Post the dedicated operational opening journal after a successful apply that matches approved totals.} {--json= : Read-only JSON fixture of expenses rows. Use when the sibling radiumbox_prod connection is not configured.} {--connection=legacy_radiumbox : Laravel connection name for SELECT-only access to radiumbox_prod.expenses} {--actor= : User id or email recorded on the opening journal} {--cash-account= : Cash account id (defaults to Main Cash Drawer)}')]
#[Description('Import RadiumBox Admin expenses into read-only Legacy Cash. Does not write radiumbox_prod or post per-row GL/Cash Book entries.')]
class ImportLegacyCashCommand extends Command
{
    public function __construct(
        private readonly LegacyCashSourceReader $reader,
        private readonly LegacyCashImportService $imports,
        private readonly OpeningBalanceService $openings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $postOpening = (bool) $this->option('post-opening');
        $json = $this->option('json');

        try {
            $rows = is_string($json) && $json !== ''
                ? $this->reader->fromJson($json)
                : $this->reader->fromConnection((string) $this->option('connection'));

            $result = $this->imports->import($rows, dryRun: ! $apply);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($apply
            ? ($result->skippedExisting > 0 && $result->imported === 0
                ? 'Legacy Cash already imported. No duplicate rows were created.'
                : 'Legacy Cash rows persisted.')
            : 'Dry-run only. No Desk rows were written.');

        $this->line('Source: '.LegacyCashContract::LEGACY_DATABASE.'.'.LegacyCashContract::LEGACY_TABLE);
        $this->line('Cutoff: '.LegacyCashContract::CUTOFF_AT.' IST');
        $this->line('Rows: '.$result->sourceRows.' (imported '.$result->imported.', skipped '.$result->skippedExisting.')');
        $this->line('Credits: '.$result->credits.' / ₹'.$result->creditTotal);
        $this->line('Debits: '.$result->debits.' / ₹'.$result->debitTotal);
        $this->line('Net: ₹'.$result->net);
        $this->line('Mapped rows: '.$result->mappedRows);
        $this->line('Unmapped rows: '.$result->unmappedRows);
        $this->line('Review rows: '.$result->reviewRows);
        $this->line('Latest source created_at: '.($result->maxCreatedAt ?? '—'));
        $this->line('Approved totals match: '.($result->matchesApprovedTotals() ? 'yes' : 'no'));

        foreach ($result->unmappedAdminCounts as $adminId => $count) {
            $this->line('Unmapped Admin '.$adminId.': '.$count.' rows');
        }

        if ($result->conflicts !== []) {
            $this->error('Conflicting existing rows: '.count($result->conflicts));

            return self::FAILURE;
        }

        if ($apply && ! (is_string($json) && $json !== '') && ! $result->matchesApprovedTotals()) {
            $this->error('Live source totals do not match the approved 1,765-row contract. Opening journal was not posted.');

            return self::FAILURE;
        }

        if (! $postOpening) {
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->error('--post-opening requires --apply.');

            return self::FAILURE;
        }

        if (! $result->matchesApprovedTotals() && $result->skippedExisting !== LegacyCashContract::EXPECTED_ROWS) {
            $this->error('Cannot post the opening journal unless imported Legacy Cash matches the approved totals.');

            return self::FAILURE;
        }

        try {
            $existingOpening = FinanceJournal::query()
                ->where('idempotency_key', LegacyCashContract::OPENING_IDEMPOTENCY_KEY)
                ->first();
            $actor = $this->resolveActor();
            $cashAccount = $this->resolveCashAccount();
            $journal = $this->openings->postLegacyAdminCashOpening($cashAccount, $actor);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Opening journal '.$journal->journal_no.' — '.LegacyCashContract::OPENING_MEMO);
        $this->line('Amount: ₹'.LegacyCashContract::OPENING_AMOUNT);
        $this->line('Idempotency: '.LegacyCashContract::OPENING_IDEMPOTENCY_KEY);
        $this->line($existingOpening !== null ? 'Existing opening journal reused.' : 'Opening journal posted.');

        return self::SUCCESS;
    }

    private function resolveActor(): User
    {
        $actor = $this->option('actor');
        if ($actor === null || $actor === '') {
            throw ValidationException::withMessages([
                'actor' => 'Pass --actor= with a user id or email before posting the opening journal.',
            ]);
        }

        $query = User::query();
        $user = is_numeric($actor)
            ? $query->find((int) $actor)
            : $query->where('email', $actor)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'actor' => 'Opening journal actor was not found.',
            ]);
        }

        return $user;
    }

    private function resolveCashAccount(): FinanceCashAccount
    {
        $id = $this->option('cash-account');
        if ($id !== null && $id !== '') {
            $account = FinanceCashAccount::query()->find((int) $id);
            if ($account === null) {
                throw ValidationException::withMessages([
                    'cash_account' => 'Cash account was not found.',
                ]);
            }

            return $account;
        }

        $account = FinanceCashAccount::query()->where('name', 'Main Cash Drawer')->first()
            ?? FinanceCashAccount::query()->orderBy('id')->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'cash_account' => 'No Desk cash account exists for the opening journal.',
            ]);
        }

        return $account;
    }
}
