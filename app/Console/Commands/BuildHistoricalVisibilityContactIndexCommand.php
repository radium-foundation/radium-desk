<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Application\ReconciledHistoricalRefundFilter;
use App\CentralWallet\Support\HistoricalGroupSiteScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

#[Signature('central-wallet:build-historical-visibility-contact-index
    {--output= : Output JSON path}
    {--dry-run : Print summary without writing}')]
#[Description('Build read-only historical wallet visibility contact index from post-cutoff wallet refunds')]
class BuildHistoricalVisibilityContactIndexCommand extends Command
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly ReconciledHistoricalRefundFilter $reconciledFilter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cutoff = (string) config('central_wallet.historical_wallet_visibility.campaign_start_date', '2026-07-15');
        $rows = DB::table('refund_requests as rr')
            ->join('orders as o', 'o.id', '=', 'rr.order_id')
            ->where('rr.approved_refund_method', 'wallet')
            ->where('rr.status', 'closed')
            ->where('rr.executed_at', '>=', $cutoff.' 00:00:00')
            ->whereNull('rr.deleted_at')
            ->orderBy('rr.id')
            ->get([
                'rr.id as refund_id',
                'rr.reference_no as desk_refund_reference',
                'rr.refund_amount',
                'o.order_id as order_number',
                'o.customer_email',
                'o.customer_phone',
            ]);

        $indexRows = [];
        $total = '0.00';

        foreach ($rows as $row) {
            $refundId = (int) $row->refund_id;
            if ($this->reconciledFilter->isProtectedFromDisplay($refundId)) {
                continue;
            }

            $email = strtolower(trim((string) $row->customer_email));
            $phoneDigits = preg_replace('/\D+/', '', (string) $row->customer_phone) ?? '';
            $emailHash = '';
            $mobileHash = '';

            if ($email !== '' && str_contains($email, '@')) {
                try {
                    $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
                } catch (InvalidArgumentException) {
                    $emailHash = '';
                }
            }

            if (strlen($phoneDigits) >= 10) {
                $normalized = strlen($phoneDigits) === 10 ? '+91'.$phoneDigits : '+'.$phoneDigits;
                $mobileHash = hash('sha256', 'historical_contact_mobile:'.$normalized);
            }

            if ($emailHash === '' && $mobileHash === '') {
                continue;
            }

            $amount = number_format((float) $row->refund_amount, 2, '.', '');
            $total = bcadd($total, $amount, 2);

            $indexRows[] = [
                'refund_id' => $refundId,
                'refund_amount' => $amount,
                'desk_refund_reference' => (string) $row->desk_refund_reference,
                'order_number' => (string) $row->order_number,
                'site' => HistoricalGroupSiteScope::inferOriginFromOrderNumber((string) $row->order_number),
                'order_email_hash' => $emailHash,
                'order_mobile_hash' => $mobileHash,
            ];
        }

        $payload = [
            'prompt_id' => 'RadiumDesk-P-30-10-39',
            'population_count' => count($indexRows),
            'population_amount' => $total,
            'source_refund_count' => $rows->count(),
            'generated_at' => now()->toIso8601String(),
            'rows' => $indexRows,
        ];

        $this->info('Indexed refunds: '.count($indexRows).' / amount: ₹'.$total);
        $this->info('Source closed wallet refunds since '.$cutoff.': '.$rows->count());

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $output = (string) ($this->option('output') ?: config('central_wallet.historical_wallet_visibility.contact_index_manifest_path'));
        File::ensureDirectoryExists(dirname($output));
        File::put($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info('Wrote '.$output);

        return self::SUCCESS;
    }

}
