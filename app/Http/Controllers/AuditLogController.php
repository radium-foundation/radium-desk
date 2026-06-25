<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const MODULE_TYPES = [
        'Order' => Order::class,
        'Incident' => \App\Models\Incident::class,
        'Remark' => \App\Models\Remark::class,
        'ApprovalNumber' => \App\Models\ApprovalNumber::class,
        'RefundRequest' => \App\Models\RefundRequest::class,
        'User' => User::class,
    ];

    public function __construct()
    {
        $this->authorizeResource(AuditLog::class, 'auditLog', [
            'only' => ['index', 'show'],
        ]);
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();

        $auditLogs = AuditLog::query()
            ->with('user')
            ->when($request->filled('user_id'), function (Builder $query) use ($request) {
                $query->where('user_id', $request->integer('user_id'));
            })
            ->when($request->filled('event'), function (Builder $query) use ($request) {
                $query->where('event', $request->string('event')->trim());
            })
            ->when($request->filled('module'), function (Builder $query) use ($request) {
                $module = $request->string('module')->trim()->toString();
                $type = self::MODULE_TYPES[$module] ?? $module;

                $query->where('auditable_type', $type);
            })
            ->when($request->filled('date_from'), function (Builder $query) use ($request) {
                $query->whereDate('created_at', '>=', $request->string('date_from')->trim());
            })
            ->when($request->filled('date_to'), function (Builder $query) use ($request) {
                $query->whereDate('created_at', '<=', $request->string('date_to')->trim());
            })
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $builder) use ($search) {
                    $builder->where('event', 'like', '%'.$search.'%')
                        ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                            $userQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });

                    if (ctype_digit($search)) {
                        $builder->orWhere('auditable_id', (int) $search);
                    }
                });
            })
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        $users = User::query()
            ->whereIn('id', AuditLog::query()->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $events = AuditLog::query()
            ->select('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event');

        $modules = collect(self::MODULE_TYPES)->keys()->sort()->values();

        return view('audit-logs.index', [
            'auditLogs' => $auditLogs,
            'users' => $users,
            'events' => $events,
            'modules' => $modules,
            'filters' => $request->only(['user_id', 'event', 'module', 'date_from', 'date_to', 'q']),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        $auditLog->load('user');

        return view('audit-logs.show', [
            'auditLog' => $auditLog,
        ]);
    }
}
