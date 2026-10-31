<?php

namespace App\Console\Commands;

use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationOutboxWriter;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(
    name: 'desk:radiumbox-statutory-receipt-requeue-awaiting',
    description: 'Re-pend outbox delivery for RadiumBox statutory receipt publications awaiting publisher activation',
)]
class RadiumBoxStatutoryReceiptRequeueAwaitingCommand extends Command
{
    protected $signature = 'desk:radiumbox-statutory-receipt-requeue-awaiting
                            {--limit= : Maximum awaiting_activation publications to consider (oldest first)}
                            {--dry-run : Report eligible counts without modifying outbox rows}';

    public function handle(RadiumBoxStatutoryReceiptPublicationOutboxWriter $writer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        $publisherEnabled = (bool) config('radiumbox.statutory_receipt_publish.enabled', false);

        $preview = $writer->previewAwaitingActivationRequeue($limit);

        if ($dryRun) {
            $this->line('dry_run=true');
            $this->line('publisher_enabled='.($publisherEnabled ? 'true' : 'false'));
            $this->line('selected='.$preview['selected']);
            $this->line('requeueable='.$preview['requeueable']);
            $this->line('requeued=0');

            return self::SUCCESS;
        }

        if (! $publisherEnabled) {
            $this->error(
                'RadiumBox statutory receipt publishing is disabled (RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED). '
                .'Enable the publisher after Owner approval, then re-run this command. No rows were changed.',
            );
            $this->line('selected='.$preview['selected']);
            $this->line('requeueable='.$preview['requeueable']);
            $this->line('requeued=0');

            return self::FAILURE;
        }

        $requeued = $writer->requeueAwaitingActivation($limit);

        $this->line('dry_run=false');
        $this->line('publisher_enabled=true');
        $this->line('selected='.$preview['selected']);
        $this->line('requeueable='.$preview['requeueable']);
        $this->line('requeued='.$requeued);
        $this->comment('Outbox workers (outbox:process / schedule:light-tick) perform HTTP delivery.');

        return self::SUCCESS;
    }
}
