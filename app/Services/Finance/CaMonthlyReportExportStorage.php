<?php

namespace App\Services\Finance;

use App\Enums\CaMonthlyReportExportFormat;
use App\Models\CaMonthlyReportExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CaMonthlyReportExportStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('ca_monthly_report.storage_disk', 'local'));
    }

    public function allocatePath(CaMonthlyReportExport $export): string
    {
        $directory = trim((string) config('ca_monthly_report.storage_directory', 'ca-monthly-report-exports'), '/');

        return $directory.'/'.Str::uuid()->toString().'.'.$export->format->extension();
    }

    public function absolutePath(CaMonthlyReportExport $export): string
    {
        if ($export->storage_path === null) {
            throw new \RuntimeException('Export artifact path is not set.');
        }

        return $this->disk()->path($export->storage_path);
    }

    public function exists(CaMonthlyReportExport $export): bool
    {
        return $export->storage_path !== null
            && $this->disk()->exists($export->storage_path);
    }

    public function deleteArtifact(CaMonthlyReportExport $export): void
    {
        if ($export->storage_path === null) {
            return;
        }

        if ($this->disk()->exists($export->storage_path)) {
            $this->disk()->delete($export->storage_path);
        }
    }

    public function temporaryLocalPath(CaMonthlyReportExportFormat $format): string
    {
        $directory = storage_path('app/tmp/ca-monthly-report');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create temporary export directory.');
        }

        return $directory.'/'.uniqid('ca-monthly-', true).'.'.$format->extension();
    }
}
