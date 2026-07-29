<?php

namespace App\Http\Controllers;

use App\Models\GmailSyncMessageFailure;
use App\Models\SystemSetting;
use App\Services\IncomingEmail\IncomingEmailGmailSyncService;
use App\Services\Operations\OperationsGmailHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class GmailAdminActionsController extends Controller
{
    public function syncNow(Request $request, IncomingEmailGmailSyncService $syncService): JsonResponse
    {
        $this->authorizeGmailAdmin($request);

        try {
            $result = $syncService->sync();
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ], 422);
        }

        return response()->json([
            'ok' => ($result['failed_mailboxes'] ?? 0) === 0,
            'message' => sprintf(
                'Synced %d mailbox(es); pulled %d; failed messages %d; failed mailboxes %d.',
                $result['mailboxes'],
                $result['pulled'],
                $result['messages_failed'] ?? 0,
                $result['failed_mailboxes'],
            ),
            'result' => $result,
        ]);
    }

    public function rebaseline(Request $request, IncomingEmailGmailSyncService $syncService, OperationsGmailHealthService $health): JsonResponse
    {
        $this->authorizeGmailAdmin($request);

        $mailbox = strtolower(trim((string) $request->input('mailbox', '')));
        $mailboxes = $health->configuredMailboxes();

        if ($mailbox === '') {
            $mailbox = $mailboxes[0] ?? '';
        }

        if ($mailbox === '' || ! in_array($mailbox, $mailboxes, true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Mailbox is not configured for Gmail sync.',
            ], 422);
        }

        try {
            $historyId = $syncService->rebaselineMailbox($mailbox);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => sprintf('Re-baselined %s to history cursor %s. Historical mail will not be imported.', $mailbox, $historyId),
            'history_id' => $historyId,
            'mailbox' => $mailbox,
        ]);
    }

    public function logs(Request $request): View
    {
        $this->authorizeGmailAdmin($request);

        $path = storage_path('logs/inbound-email-gmail-sync.log');
        $lines = [];

        if (is_file($path)) {
            $contents = @file($path, FILE_IGNORE_NEW_LINES);
            if (is_array($contents)) {
                $lines = array_slice($contents, -200);
            }
        }

        return view('admin.gmail.logs', [
            'lines' => $lines,
            'path' => $path,
        ]);
    }

    public function failedMessages(Request $request): View
    {
        $this->authorizeGmailAdmin($request);

        $failures = GmailSyncMessageFailure::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('admin.gmail.failed-messages', [
            'failures' => $failures,
        ]);
    }

    private function authorizeGmailAdmin(Request $request): void
    {
        abort_unless($request->user()?->can('update', SystemSetting::class), 403);
    }
}
