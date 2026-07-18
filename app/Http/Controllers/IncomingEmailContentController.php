<?php

namespace App\Http\Controllers;

use App\Models\IncomingEmailMessage;
use App\Services\IncomingEmail\IncomingEmailLiveContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class IncomingEmailContentController extends Controller
{
    public function __construct(
        private readonly IncomingEmailLiveContentService $liveContentService,
    ) {}

    public function show(IncomingEmailMessage $incomingEmailMessage): JsonResponse
    {
        $this->authorizeIncomingEmail($incomingEmailMessage);

        return response()->json(
            $this->liveContentService->resolve($incomingEmailMessage),
        );
    }

    public function downloadAttachment(
        IncomingEmailMessage $incomingEmailMessage,
        string $attachment,
    ): Response {
        $this->authorizeIncomingEmail($incomingEmailMessage);

        $download = $this->liveContentService->downloadAttachment(
            $incomingEmailMessage,
            $attachment,
        );

        $filename = str_replace(['"', '\\'], '', $download['filename']);

        return response($download['binary'])
            ->header('Content-Type', $download['mime_type'])
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    private function authorizeIncomingEmail(IncomingEmailMessage $message): void
    {
        if ($message->incident_id !== null) {
            $message->loadMissing('incident');
            $this->authorize('view', $message->incident);

            return;
        }

        if ($message->order_id !== null) {
            $message->loadMissing('order');
            $this->authorize('view', $message->order);

            return;
        }

        abort(404);
    }
}
