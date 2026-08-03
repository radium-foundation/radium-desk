<?php

namespace App\Services\OutgoingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class OutgoingEmailReplyGate
{
    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function evaluate(User $user, IncomingEmailMessage $message): array
    {
        if (! (bool) config('inbound_email.reply.enabled')) {
            return ['allowed' => false, 'reason' => 'reply_disabled'];
        }

        if (! $user->can('email.reply')) {
            return ['allowed' => false, 'reason' => 'missing_permission'];
        }

        $mailbox = strtolower(trim((string) $message->mailbox));
        $allowedMailboxes = array_map('strtolower', config('inbound_email.reply.mailboxes', []));

        if ($mailbox === '' || ! in_array($mailbox, $allowedMailboxes, true)) {
            return ['allowed' => false, 'reason' => 'mailbox_not_enabled'];
        }

        if (! in_array($message->status, [
            IncomingEmailMessageStatus::Linked,
            IncomingEmailMessageStatus::HistoricalCustomer,
        ], true)) {
            return ['allowed' => false, 'reason' => 'message_not_operational'];
        }

        if ($message->thread_id === null || trim((string) $message->thread_id) === '') {
            return ['allowed' => false, 'reason' => 'missing_thread_id'];
        }

        if ($message->from_email === null || trim((string) $message->from_email) === '') {
            return ['allowed' => false, 'reason' => 'missing_recipient'];
        }

        if ($message->incident_id === null && $message->order_id === null) {
            return ['allowed' => false, 'reason' => 'unlinked_message'];
        }

        if ($message->incident_id !== null) {
            $message->loadMissing('incident');

            if ($message->incident === null || Gate::forUser($user)->denies('view', $message->incident)) {
                return ['allowed' => false, 'reason' => 'cannot_view_incident'];
            }
        } elseif ($message->order_id !== null) {
            $message->loadMissing('order');

            if ($message->order === null || Gate::forUser($user)->denies('view', $message->order)) {
                return ['allowed' => false, 'reason' => 'cannot_view_order'];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }
}
