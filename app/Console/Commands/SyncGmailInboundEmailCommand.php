<?php

namespace App\Console\Commands;

use App\Services\IncomingEmail\IncomingEmailGmailSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('inbound-email:sync-gmail')]
#[Description('Pull new Gmail messages into the existing incoming email intake pipeline')]
class SyncGmailInboundEmailCommand extends Command
{
    public function __construct(
        private readonly IncomingEmailGmailSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('inbound_email.enabled') || ! config('inbound_email.gmail.enabled')) {
            $this->info('Inbound email Gmail sync is disabled.');

            return self::SUCCESS;
        }

        try {
            $result = $this->syncService->sync();
        } catch (Throwable $exception) {
            Log::error('[GmailInbound] scheduled sync failed.', [
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        Log::info('[GmailInbound] Sync run completed.', [
            'mailboxes' => $result['mailboxes'],
            'pulled' => $result['pulled'],
            'ingested' => $result['ingested'],
            'skipped' => $result['skipped'],
            'failed_mailboxes' => $result['failed_mailboxes'],
        ]);

        $this->info(sprintf(
            'Synced %d mailbox(es); pulled %d; skipped %d; failed %d.',
            $result['mailboxes'],
            $result['pulled'],
            $result['skipped'],
            $result['failed_mailboxes'],
        ));

        return $result['failed_mailboxes'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
