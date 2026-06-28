<?php

namespace App\Http\Controllers;

use App\Models\CashfreeWebhookLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashfreeWebhookLogController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CashfreeWebhookLog::class, 'cashfreeWebhookLog', [
            'only' => ['index', 'show'],
        ]);
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();

        $webhookLogs = CashfreeWebhookLog::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                if (ctype_digit($search)) {
                    $query->whereKey((int) $search);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->latest('received_at')
            ->paginate(20)
            ->withQueryString();

        return view('cashfree.webhook-explorer.index', [
            'webhookLogs' => $webhookLogs,
            'filters' => $request->only(['q']),
        ]);
    }

    public function show(CashfreeWebhookLog $cashfreeWebhookLog): View
    {
        $cashfreeWebhookLog->load('incident');

        return view('cashfree.webhook-explorer.show', [
            'webhookLog' => $cashfreeWebhookLog,
        ]);
    }
}
