<?php

namespace App\Console\Commands;

use App\Enums\PosCustomerRequestedSerialExchangeStatutoryTreatment;
use App\Models\User;
use App\Services\Pos\PosCustomerRequestedSerialExchangeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('pos:customer-serial-exchange
    {--action=execute : execute or record-statutory}
    {--sale-id= : inventory_sales.id}
    {--original= : original serial number on the sale}
    {--replacement= : available replacement serial at the sale branch}
    {--idempotency-key= : durable idempotency key}
    {--reason= : business reason for the exchange}
    {--attestation= : owner physical receipt and resaleable attestation (execute)}
    {--statutory-decision= : CA decision code (record-statutory)}
    {--statutory-evidence= : CA evidence reference (record-statutory)}
    {--actor= : user id or email performing the action}
    {--dry-run : validate payload only; no persistence}')]
#[Description('Controlled customer-requested POS serial exchange (statutory gate + execute). Refuses production APP_ENV.')]
class PosCustomerSerialExchangeCommand extends Command
{
    public function __construct(
        private readonly PosCustomerRequestedSerialExchangeService $exchanges,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This command is not available in production APP_ENV.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn('Dry-run: validate only. No database writes were performed.');

            return $this->validateInputs() ? self::SUCCESS : self::FAILURE;
        }

        $actor = $this->resolveActor();

        $action = strtolower((string) $this->option('action'));
        $base = [
            'inventory_sale_id' => (int) $this->option('sale-id'),
            'original_serial_number' => (string) $this->option('original'),
            'replacement_serial_number' => (string) $this->option('replacement'),
            'idempotency_key' => (string) $this->option('idempotency-key'),
            'exchange_reason' => (string) $this->option('reason'),
        ];

        try {
            if ($action === 'record-statutory') {
                $exchange = $this->exchanges->recordStatutoryApproval($actor, [
                    ...$base,
                    'statutory_treatment_decision' => (string) $this->option('statutory-decision'),
                    'statutory_evidence_reference' => (string) $this->option('statutory-evidence'),
                ]);
                $this->info('Statutory approval recorded for exchange #'.$exchange->id);

                return self::SUCCESS;
            }

            $exchange = $this->exchanges->execute($actor, [
                ...$base,
                'physical_receipt_attestation_reason' => (string) $this->option('attestation'),
            ]);
            $this->info('Exchange executed #'.$exchange->id.' at '.$exchange->executed_at);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
    }

    private function validateInputs(): bool
    {
        $required = ['sale-id', 'original', 'replacement', 'idempotency-key', 'reason'];
        foreach ($required as $key) {
            if (trim((string) $this->option($key)) === '') {
                $this->error("Missing --{$key}");

                return false;
            }
        }

        $action = strtolower((string) $this->option('action'));
        if ($action === 'record-statutory') {
            if (PosCustomerRequestedSerialExchangeStatutoryTreatment::tryFrom((string) $this->option('statutory-decision')) === null) {
                $this->error('Invalid --statutory-decision');

                return false;
            }
            if (trim((string) $this->option('statutory-evidence')) === '') {
                $this->error('Missing --statutory-evidence');

                return false;
            }
        }

        if ($action === 'execute' && trim((string) $this->option('attestation')) === '') {
            $this->error('Missing --attestation for execute');

            return false;
        }

        return true;
    }

    private function resolveActor(): User
    {
        $token = trim((string) $this->option('actor'));
        if ($token === '') {
            throw ValidationException::withMessages([
                'actor' => 'Pass --actor with a user id or email.',
            ]);
        }

        $user = is_numeric($token)
            ? User::query()->find((int) $token)
            : User::query()->where('email', $token)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'actor' => 'Actor user was not found.',
            ]);
        }

        return $user;
    }
}
