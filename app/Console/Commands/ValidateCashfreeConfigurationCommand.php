<?php

namespace App\Console\Commands;

use App\Services\Cashfree\CashfreeConfigurationValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cashfree:validate-config')]
#[Description('Validate Cashfree webhook configuration for production readiness')]
class ValidateCashfreeConfigurationCommand extends Command
{
    public function handle(CashfreeConfigurationValidator $validator): int
    {
        $failures = $validator->failures();

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->info('Cashfree configuration is valid.');

        return self::SUCCESS;
    }
}
