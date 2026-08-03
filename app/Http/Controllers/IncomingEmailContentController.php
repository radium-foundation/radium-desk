<?php

namespace App\Http\Controllers;

use App\Models\IncomingEmailMessage;
use App\Models\OutgoingEmailMessage;
use App\Services\IncomingEmail\IncomingEmailLiveContentService;
use App\Services\OutgoingEmail\OutgoingEmailReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class IncomingEmailContentController extends Controller
{
    public function __construct(
        private readonly IncomingEmailLiveContentService $liveContentService,
        private readonly OutgoingEmailReplyService $replyService,
    ) {}

    public function show(IncomingEmailMessage $incomingEmailMessage): JsonResponse
    {
        $this->authorizeIncomingEmail($incomingEmailMessage);

        $payload = $this->liveContentService->resolve($incomingEmailMessage);
        $context = $this->replyService->context(request()->user(), $incomingEmailMessage);

        return response()->json(array_merge($payload, [
            'can_reply' => $context['can_reply'],
            'reply_reason' => $context['reason'],
            'reply_mailbox' => $context['mailbox'],
            'id' => $incomingEmailMessage->id,
        ]));
    }

    public function replyContext(IncomingEmailMessage $incomingEmailMessage): JsonResponse
    {
        $this->authorizeIncomingEmail($incomingEmailMessage);

        return response()->json(
            $this->replyService->context(request()->user(), $incomingEmailMessage),
        );
    }

    public function replyPreview(Request $request, IncomingEmailMessage $incomingEmailMessage): JsonResponse
    {
        $this->authorizeIncomingEmail($incomingEmailMessage);

        $validated = $request->validate([
            'template_key' => ['required', 'string', 'max:64'],
        ]);

        $preview = $this->replyService->previewTemplate(
            $request->user(),
            $incomingEmailMessage,
            (string) $validated['template_key'],
        );

        return response()->json($preview);
    }

    public function reply(Request $request, IncomingEmailMessage $incomingEmailMessage): JsonResponse
    {
        $this->authorizeIncomingEmail($incomingEmailMessage);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:998'],
            'body_html' => ['required', 'string'],
            'template_key' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $outgoing = $this->replyService->send(
                $request->user(),
                $incomingEmailMessage,
                (string) $validated['subject'],
                (string) $validated['body_html'],
                isset($validated['template_key']) ? (string) $validated['template_key'] : null,
            );
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Failed to send email reply.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Reply sent.',
            'outgoing_email_message' => $this->serializeOutgoing($outgoing),
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function serializeOutgoing(OutgoingEmailMessage $outgoing): array
    {
        return [
            'id' => $outgoing->id,
            'status' => $outgoing->status->value,
            'subject' => $outgoing->subject,
            'to_email' => $outgoing->to_email,
            'mailbox' => $outgoing->mailbox,
            'thread_id' => $outgoing->thread_id,
            'provider_message_id' => $outgoing->provider_message_id,
            'sent_at' => $outgoing->sent_at?->toIso8601String(),
        ];
    }
}
