<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Application\E2HistoricalSettlementManifestLoader;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CentralWalletE2VerificationCohortManifestBuildCommand extends Command
{
    protected $signature = 'central-wallet:e2-verification-cohort-manifest-build
                            {--output= : Output path (default: storage/app/private/cw-e2-verification-cohort-manifest-p30-10-22.json)}
                            {--dry-run : Validate without writing}';

    protected $description = 'Build E-2 verification cohort manifest with order_email_hash index (P-30-10-22)';

    public function handle(
        E2HistoricalSettlementManifestLoader $settlementLoader,
        CustomerIdentitySubjectHasher $hasher,
    ): int {
        try {
            $settlement = $settlementLoader->load();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        foreach ($settlement['rows'] as $row) {
            $refundId = (int) $row['refund_id'];
            $refund = RefundRequest::query()->with('order')->find($refundId);
            if ($refund === null || ! $refund->order instanceof Order) {
                $this->error('Refund '.$refundId.' missing order linkage');

                return self::FAILURE;
            }

            $email = trim((string) $refund->order->customer_email);
            if ($email === '' || ! str_contains($email, '@')) {
                $this->error('Refund '.$refundId.' missing order email');

                return self::FAILURE;
            }

            try {
                $emailHash = $hasher->hashVerifiedEmail($email);
            } catch (InvalidArgumentException) {
                $this->error('Refund '.$refundId.' has invalid order email');

                return self::FAILURE;
            }

            $rows[] = [
                'refund_id' => $refundId,
                'refund_amount' => (string) $row['refund_amount'],
                'site' => (string) $row['site'],
                'order_number' => (string) ($row['order_number'] ?? $refund->order->order_id),
                'order_email_hash' => $emailHash,
            ];
        }

        $manifest = [
            'cohort_id' => E2HistoricalSettlementManifestLoader::COHORT_ID,
            'prompt_id' => 'RadiumDesk-P-30-10-22',
            'refund_count' => count($rows),
            'refund_amount' => $settlement['refund_amount'],
            'settlement_batch_id' => $settlement['batch_id'],
            'owner_approval_ref' => $settlement['owner_approval_ref'] ?? E2HistoricalSettlementManifestLoader::DEFAULT_OWNER_APPROVAL_REF,
            'rows' => $rows,
        ];

        if ($this->option('dry-run')) {
            $this->info('Dry-run OK: '.count($rows).' rows / ₹'.$manifest['refund_amount']);

            return self::SUCCESS;
        }

        $output = $this->option('output')
            ?: storage_path('app/private/cw-e2-verification-cohort-manifest-p30-10-22.json');

        File::put($output, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->info('Wrote '.$output);

        return self::SUCCESS;
    }
}
