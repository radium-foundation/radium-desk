<?php

namespace App\Console\Commands;

use App\Services\SerialValidation\SerialLearningExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('serial:export-learning
    {--output= : Write JSON export to this file path}
    {--pretty : Pretty-print JSON output}')]
#[Description('Export PII-safe serial learning samples for Ira rule improvement')]
class ExportSerialLearningCommand extends Command
{
    public function __construct(
        private readonly SerialLearningExportService $exportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $export = $this->exportService->export();
        $flags = $this->option('pretty') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0;
        $json = json_encode($export->toArray(), $flags);

        if (! is_string($json)) {
            $this->error('Failed to encode serial learning export.');

            return self::FAILURE;
        }

        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $json);
            $this->info("Serial learning export written to {$outputPath}");
        } else {
            $this->line($json);
        }

        $this->newLine();
        $this->info(sprintf(
            'Export summary: %d valid serials, %d failed validations, %d corrections.',
            $export->validSerialCount,
            $export->failedValidationCount,
            $export->correctedHistoryCount,
        ));

        return self::SUCCESS;
    }
}
