<?php

namespace App\Console\Commands;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\B2cCgstSgstSnapshotRemediation;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('desk:remediate-b2c-cgst-sgst-snapshot
    {--manifest= : Absolute path to manifest JSON}
    {--apply : Apply the verified manifest to production snapshots}
    {--regenerate-pdf : Regenerate statutory PDF presentations for corrected invoices}
    {--actor=RadiumDesk-P-23-09-18 : Operator or prompt id for audit logging}')]
#[Description('Owner-authorized B2C-only CGST/SGST snapshot remediation (manifest, backup, apply, verify).')]
class RemediateB2cCgstSgstSnapshotCommand extends Command
{
    public function handle(
        B2cCgstSgstSnapshotRemediation $remediation,
        StatutoryDocumentService $documents,
    ): int {
        $actor = trim((string) $this->option('actor'));
        $manifestPath = $this->resolveManifestPath();

        if (! $this->option('apply')) {
            $manifest = $remediation->buildManifest();
            $this->writeManifest($manifestPath, $manifest);
            $backupPath = $this->backupPath($manifestPath);
            $remediation->writeBackup($manifest, $backupPath);

            $errors = $remediation->validateManifest($manifest);
            if ($errors !== []) {
                $this->error('Manifest validation failed:');
                foreach ($errors as $error) {
                    $this->line(' - '.$error);
                }

                return self::FAILURE;
            }

            $this->info('Manifest built and validated for 70 B2C invoices.');
            $this->line('Manifest: '.$manifestPath);
            $this->line('Backup: '.$backupPath);
            $this->line('SHA256: '.$manifest['manifest_sha256']);
            $this->info('Dry-run complete. Re-run with --apply to mutate production snapshots.');

            return self::SUCCESS;
        }

        if (! is_file($manifestPath)) {
            $this->error('Manifest not found: '.$manifestPath);

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            $this->error('Invalid manifest JSON.');

            return self::FAILURE;
        }

        $errors = $remediation->validateManifest($manifest);
        if ($errors !== []) {
            $this->error('Manifest validation failed before apply:');
            foreach ($errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $backupPath = $this->backupPath($manifestPath);
        $remediation->writeBackup($manifest, $backupPath);
        $this->info('Pre-apply backup written: '.$backupPath);

        $result = $remediation->apply($manifest, $actor);
        $this->info('Applied corrections to '.$result['applied'].' invoices.');

        $verification = $this->verifyGlobalState();
        $this->line(json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($verification['target_unequal'] !== 0 || $verification['total_unequal'] !== 7) {
            $this->error('Global verification failed.');

            return self::FAILURE;
        }

        if ($this->option('regenerate-pdf')) {
            foreach ($manifest['invoices'] as $row) {
                $invoice = StatutoryInvoice::query()->with(['items', 'eInvoiceRecord'])->find((int) $row['invoice_id']);
                if ($invoice === null) {
                    $this->error('Missing invoice for PDF regen: '.$row['invoice_number']);

                    return self::FAILURE;
                }
                $documents->regeneratePresentation($invoice);
                $this->line('PDF regenerated: '.$invoice->invoice_number);
            }
        }

        $this->info('B2C CGST/SGST remediation completed successfully.');

        return self::SUCCESS;
    }

    private function resolveManifestPath(): string
    {
        $custom = trim((string) $this->option('manifest'));
        if ($custom !== '') {
            return $custom;
        }

        return storage_path('app/private/remediation/RadiumDesk-P-23-09-18-b2c-cgst-sgst-manifest.json');
    }

    private function backupPath(string $manifestPath): string
    {
        return preg_replace('/\.json$/', '.pre-apply-backup.json', $manifestPath)
            ?: $manifestPath.'.pre-apply-backup.json';
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Failed to create manifest directory: '.$directory);
        }

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException('Failed to write manifest: '.$path);
        }
    }

    /**
     * @return array<string, int>
     */
    private function verifyGlobalState(): array
    {
        $b2b = B2cCgstSgstSnapshotRemediation::B2B_EXCLUDED_INVOICE_NUMBERS;
        $targetIds = DB::table('statutory_invoices as si')
            ->join('e_invoice_records as eir', 'eir.invoice_id', '=', 'si.id')
            ->where('si.issued_at', '>=', B2cCgstSgstSnapshotRemediation::SCOPE_START)
            ->where('si.status', 'issued')
            ->whereRaw('COALESCE(si.igst, 0) = 0')
            ->whereRaw('ROUND(COALESCE(si.cgst, 0) * 100) <> ROUND(COALESCE(si.sgst, 0) * 100)')
            ->where(function ($query): void {
                $query->whereNull('si.buyer_gstin')->orWhereRaw('TRIM(si.buyer_gstin) = ?', ['']);
            })
            ->where('eir.status', 'skipped')
            ->whereNull('eir.irn')
            ->whereNotIn('si.invoice_number', $b2b)
            ->where('si.invoice_number', '<>', B2cCgstSgstSnapshotRemediation::UNKNOWN_EXCLUDED_INVOICE_NUMBER)
            ->pluck('si.id');

        $targetUnequal = DB::table('statutory_invoices')
            ->whereIn('id', $targetIds)
            ->whereRaw('ROUND(COALESCE(cgst, 0) * 100) <> ROUND(COALESCE(sgst, 0) * 100)')
            ->count();

        return [
            'total_unequal' => (int) DB::table('statutory_invoices')
                ->where('issued_at', '>=', B2cCgstSgstSnapshotRemediation::SCOPE_START)
                ->where('status', 'issued')
                ->whereRaw('COALESCE(igst, 0) = 0')
                ->whereRaw('ROUND(COALESCE(cgst, 0) * 100) <> ROUND(COALESCE(sgst, 0) * 100)')
                ->count(),
            'target_unequal' => $targetUnequal,
            'pre_scope_unequal' => (int) DB::table('statutory_invoices')
                ->where('issued_at', '<', B2cCgstSgstSnapshotRemediation::SCOPE_START)
                ->where('status', 'issued')
                ->whereRaw('COALESCE(igst, 0) = 0')
                ->whereRaw('ROUND(COALESCE(cgst, 0) * 100) <> ROUND(COALESCE(sgst, 0) * 100)')
                ->count(),
        ];
    }
}
