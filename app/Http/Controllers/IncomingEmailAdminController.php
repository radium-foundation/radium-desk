<?php

namespace App\Http\Controllers;

use App\Enums\IncomingEmailIntakeQueue;
use App\Models\SystemSetting;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IncomingEmailAdminController extends Controller
{
    public function index(Request $request, IncomingEmailIntakeCounterService $counters): View
    {
        $this->authorizeEmailAdmin($request);

        $queue = IncomingEmailIntakeQueue::tryFrom((string) $request->query('queue', ''))
            ?? IncomingEmailIntakeQueue::NeedsHuman;

        $messages = $counters
            ->queryForQueue($queue)
            ->limit(100)
            ->get();

        $counts = $counters->counts();

        return view('admin.incoming-emails.index', [
            'queue' => $queue,
            'messages' => $messages,
            'counts' => $counts,
            'queues' => IncomingEmailIntakeQueue::cases(),
        ]);
    }

    private function authorizeEmailAdmin(Request $request): void
    {
        abort_unless($request->user()?->can('update', SystemSetting::class), 403);
        abort_unless(config('inbound_email.enabled'), 404);
    }
}
