<?php

namespace App\Console\Commands;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\EInvoiceStoredGstGuard;
use App\Services\StatutoryInvoice\Inv2767116CgstSgstSnapshotRemediation;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('desk:remediate-inv-2767116-cgst-sgst-snapshot
    {--manifest= : Absolute path to manifest JSON}
    {--apply : Apply the verified manifest to production snapshots}
    {--regenerate-pdf : Regenerate statutory PDF presentation}
    {--actor=RadiumDesk-P-23-09-21 : Operator or prompt id for audit logging}')]
#[Description('Owner-authorized INV-2767116 CGST/SGST snapshot remediation (manifest, backup, apply, PDF). No IRP/e_invoice mutation.')]
class RemediateInv2767116CgstSgstSnapshotCommand extends Command
{
    public function handle(
        Inv2767116CgstSgstSnapshotRemediation $remediation,
        StatutoryDocumentService $documents,
    ): int {
        $actor = trim((string) $this->option('actor'));
        $manifestPath = $this->resolveManifestPath();

        if ($this->option('apply')) {
            config([
                'statutory_invoices.einvoice.irp_submission_held_invoice_ids' => [
                    Inv2767116CgstSgstSnapshotRemediation::TARGET_INVOICE_ID,
                ],
            ]);
        }

        if (! $this->option('apply')) {
            $manifest = $remediation->buildManifest();
            $this->writeManifest($manifestPath, $manifest);
            $dbBackupPath = $this->databaseBackupPath($manifestPath);
            $remediation->writeDatabaseBackup($manifest, $dbBackupPath);

            $errors = $remediation->validateManifest($manifest);
            if ($errors !== []) {
                $this->error('Manifest validation failed:');
                foreach ($errors as $error) {
                    $this->line(' - '.$error);
                }

                return self::FAILURE;
            }

            $this->info('Manifest built and validated for INV-2767116.');
            $this->line('Manifest: '.$manifestPath);
            $this->line('Database backup: '.$dbBackupPath);
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

        $dbBackupPath = $this->databaseBackupPath($manifestPath);
        $remediation->writeDatabaseBackup($manifest, $dbBackupPath);
        $this->info('Pre-apply database backup written: '.$dbBackupPath);

        $result = $remediation->apply($manifest, $actor);
        $this->info('Applied corrections to '.$result['applied'].' invoice(s).');

        $verification = $this->verifyGlobalState();
        $this->line(json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($verification['total_unequal'] !== 0 || $verification['target_unequal'] !== 0) {
            $this->error('Global verification failed.');

            return self::FAILURE;
        }

        if ($this->option('regenerate-pdf')) {
            $invoice = StatutoryInvoice::query()
                ->with(['items', 'eInvoiceRecord'])
                ->find(Inv2767116CgstSgstSnapshotRemediation::TARGET_INVOICE_ID);
            if ($invoice === null) {
                $this->error('Missing invoice for PDF regen.');

                return self::FAILURE;
            }

            if (EInvoiceStoredGstGuard::missingReasons($invoice) !== []) {
                $this->error('GST guard failed before PDF regen.');

                return self::FAILURE;
            }

            $documents->regeneratePresentation($invoice);
            $this->line('PDF regenerated: '.$invoice->invoice_number);
        }

        $this->info('INV-2767116 CGST/SGST remediation completed successfully.');

        return self::SUCCESS;
    }

    private function resolveManifestPath(): string
    {
        $custom = trim((string) $this->option('manifest'));
        if ($custom !== '') {
            return $custom;
        }

        return storage_path('app/private/remediation/RadiumDesk-P-23-09-21-inv-2767116-cgst-sgst-manifest.json');
    }

    private function databaseBackupPath(string $manifestPath): string
    {
        return preg_replace('/\.json$/', '.database-backup.json', $manifestPath)
            ?: $manifestPath.'.database-backup.json';
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
     * @return array<string, int|string|null>
     */
    private function verifyGlobalState(): array
    {
        $targetId = Inv2767116CgstSgstSnapshotRemediation::TARGET_INVOICE_ID;

        return [
            'total_unequal' => (int) DB::table('statutory_invoices')
                ->where('issued_at', '>=', Inv2767116CgstSgstSnapshotRemediation::SCOPE_START)
                ->where('status', 'issued')
                ->whereRaw('COALESCE(igst, 0) = 0')
                ->whereRaw('ROUND(COALESCE(cgst, 0) * 100) <> ROUND(COALESCE(sgst, 0) * 100)')
                ->count(),
            'target_unequal' => (int) DB::table('statutory_invoices')
                ->where('id', $targetId)
                ->whereRaw('ROUND(COALESCE(cgst, 0) * 100) <> ROUND(COALESCE(sgst, 0) * 100)')
                ->count(),
            'pre_scope_unequal' => (int) DB::table('statutory_invoices')
                ->where('issued_at', '<', Inv2767116CgstSgstSnapshotRemediation::SCOPE_START)
                ->where('status', 'issued')
                ->whereRaw('COALESCE(igst, 0) = 0')
                ->whereRaw('ROUND(COALESCE(cgst, 0) * 100) <> ROUND(COALESCE(sgst, 0) * 100)')
                ->count(),
            'e_invoice_records_status_changed' => (int) DB::table('e_invoice_records')
                ->where('invoice_id', $targetId)
                ->where('status', '<>', 'skipped')
                ->count(),
        ];
    }
}
