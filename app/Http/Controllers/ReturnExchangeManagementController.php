<?php

namespace App\Http\Controllers;

use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeHybridApprovalType;
use App\Enums\ReturnExchangeReplacementCategory;
use App\Http\Requests\PlanReturnExchangeReplacementRequest;
use App\Http\Requests\RecordReturnExchangeHybridApprovalRequest;
use App\Http\Requests\StoreHybridReturnExchangeCaseRequest;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\ReturnExchangeCase;
use App\Policies\ReturnExchangeHybridPolicy;
use App\Services\ReturnExchange\ReturnExchangeCaseWorkflowReadinessService;
use App\Services\ReturnExchange\ReturnExchangeHybridApprovalService;
use App\Services\ReturnExchange\ReturnExchangeHybridIntakeService;
use App\Support\Inventory\InventorySerialNumber;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use App\Support\ReturnExchange\ReturnExchangePos6754DemoScenario;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReturnExchangeManagementController extends Controller
{
    public function __construct(
        private readonly ReturnExchangeHybridIntakeService $hybridIntake,
        private readonly ReturnExchangeHybridApprovalService $approvalService,
        private readonly ReturnExchangeCaseWorkflowReadinessService $readiness,
    ) {
        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user()?->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_MANAGE), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $query = ReturnExchangeCase::query()
            ->with([
                'requester',
                'branch',
                'inventorySale',
                'lines',
                'hybridApprovals',
            ])
            ->orderByDesc('id');

        ReturnExchangeCaseBranchAccess::constrainVisible($query, $request->user());

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('category')) {
            $query->where('replacement_category', (string) $request->string('category'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->integer('branch_id'));
        }

        if ($request->filled('sale_no')) {
            $saleNo = trim((string) $request->string('sale_no'));
            $query->whereHas('inventorySale', fn ($q) => $q->where('sale_no', 'like', '%'.$saleNo.'%'));
        }

        if ($request->filled('serial')) {
            $serial = InventorySerialNumber::normalize((string) $request->string('serial'));
            $query->where(function ($outer) use ($serial): void {
                $outer->where('planned_replacement_serial_number', 'like', '%'.$serial.'%')
                    ->orWhereHas('lines', fn ($line) => $line->where('serial_number', 'like', '%'.$serial.'%'));
            });
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->string('q'));
            $query->where(function ($outer) use ($q): void {
                $outer->where('case_no', 'like', '%'.$q.'%')
                    ->orWhere('reason', 'like', '%'.$q.'%');
            });
        }

        return view('return-exchange-management.index', [
            'cases' => $query->paginate(25)->withQueryString(),
            'categories' => ReturnExchangeReplacementCategory::cases(),
            'filters' => $request->only(['q', 'status', 'category', 'branch_id', 'sale_no', 'serial']),
        ]);
    }

    public function create(Request $request): View
    {
        $sale = null;
        $salePayload = null;

        if ($request->filled('inventory_sale_id')) {
            $sale = InventorySale::query()
                ->with(['branch', 'statutoryInvoice', 'serials.serial.product'])
                ->find((int) $request->integer('inventory_sale_id'));

            if ($sale !== null) {
                $salePayload = $this->salePayload($sale);
            }
        } elseif ($request->filled('sale_no')) {
            $sale = InventorySale::query()
                ->with(['branch', 'statutoryInvoice', 'serials.serial.product'])
                ->where('sale_no', trim((string) $request->string('sale_no')))
                ->first();
            if ($sale !== null) {
                $salePayload = $this->salePayload($sale);
            }
        }

        return view('return-exchange-management.create', [
            'categories' => ReturnExchangeReplacementCategory::cases(),
            'sale' => $sale,
            'salePayload' => $salePayload,
        ]);
    }

    public function store(StoreHybridReturnExchangeCaseRequest $request): RedirectResponse
    {
        $case = $this->hybridIntake->create($request->user(), $request->validated());

        return redirect()
            ->route('return-exchange-management.show', $case)
            ->with('status', 'Hybrid return case '.$case->case_no.' created for workflow review.');
    }

    public function show(Request $request, ReturnExchangeCase $returnCase): View
    {
        $this->authorize('view', $returnCase);

        $returnCase->load([
            'lines',
            'requester',
            'branch',
            'inventorySale.statutoryInvoice',
            'inventorySale.serials.serial.product',
            'receipts.line',
            'receipts.serial',
            'receipts.receiver',
            'receipts.inspector',
            'hybridApprovals.decider',
            'serialReplacements.returnedSerial',
            'serialReplacements.replacementSerial',
        ]);

        $hybridPolicy = app(ReturnExchangeHybridPolicy::class);
        $user = $request->user();
        $approvalPermissions = [];
        foreach (ReturnExchangeHybridApprovalType::cases() as $type) {
            $approvalPermissions[$type->value] = $user !== null && $hybridPolicy->recordApproval($user, $returnCase, $type);
        }

        return view('return-exchange-management.show', [
            'case' => $returnCase,
            'checklist' => $this->readiness->checklist($returnCase),
            'blockingReasons' => $this->readiness->blockingReasons($returnCase),
            'mayComplete' => $this->readiness->mayAttemptCompletion($returnCase),
            'approvalTypes' => ReturnExchangeHybridApprovalType::cases(),
            'approvalPermissions' => $approvalPermissions,
            'canPlanReplacement' => $user !== null && $hybridPolicy->planReplacement($user, $returnCase),
            'legacyShowUrl' => route('return-cases.show', $returnCase),
        ]);
    }

    public function planReplacement(
        PlanReturnExchangeReplacementRequest $request,
        ReturnExchangeCase $returnCase,
    ): RedirectResponse {
        $serialNumber = InventorySerialNumber::normalize((string) $request->string('planned_replacement_serial_number'));

        $serial = InventorySerial::query()
            ->where('serial_number', $serialNumber)
            ->first();

        if ($serial === null) {
            return back()->withErrors([
                'planned_replacement_serial_number' => 'Serial not found in inventory master (planning only — not allocated).',
            ]);
        }

        if ($serial->status !== InventorySerialStatus::Available) {
            return back()->withErrors([
                'planned_replacement_serial_number' => 'Replacement serial must be available for planning. Current status: '.$serial->status->value,
            ]);
        }

        $returnCase->update([
            'planned_replacement_serial_number' => $serialNumber,
            'planned_replacement_product_id' => $serial->product_id,
            'price_difference_amount' => $request->input('price_difference_amount'),
        ]);

        return back()->with('status', 'Planned replacement recorded (intent only — stock not allocated).');
    }

    public function recordApproval(
        RecordReturnExchangeHybridApprovalRequest $request,
        ReturnExchangeCase $returnCase,
    ): RedirectResponse {
        $type = ReturnExchangeHybridApprovalType::from((string) $request->string('approval_type'));

        $this->approvalService->record(
            $request->user(),
            $returnCase,
            $type,
            $request->validated(),
        );

        if ($type === ReturnExchangeHybridApprovalType::PriceDifference
            && $request->string('decision')->toString() === 'approved') {
            $returnCase->update(['price_difference_verified' => true]);
        }

        return back()->with('status', $type->label().' decision recorded.');
    }

    public function attemptCompletion(Request $request, ReturnExchangeCase $returnCase): RedirectResponse
    {
        $this->authorize('view', $returnCase);

        return back()->withErrors([
            'completion' => 'Case completion and sale-row exchange are deliberately disabled in this release. Owner-approved statutory and orchestration work is required.',
        ]);
    }

    public function demoPos6754(Request $request): View
    {
        abort_unless(app(ReturnExchangeHybridPolicy::class)->viewDemo($request->user()), 403);

        return view('return-exchange-management.demo-pos-6754', [
            'scenario' => ReturnExchangePos6754DemoScenario::data(),
        ]);
    }

    public function lookupPosSale(Request $request): View
    {
        $sale = null;
        $saleNo = trim((string) $request->string('sale_no'));

        if ($saleNo !== '') {
            $sale = InventorySale::query()
                ->with(['branch', 'statutoryInvoice', 'serials.serial.product'])
                ->where('sale_no', $saleNo)
                ->first();
        }

        return view('return-exchange-management.partials.pos-sale-lookup', [
            'sale' => $sale,
            'salePayload' => $sale !== null ? $this->salePayload($sale) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(InventorySale $sale): array
    {
        return [
            'id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'status' => $sale->status instanceof InventorySaleStatus ? $sale->status->value : (string) $sale->status,
            'branch' => $sale->branch?->name,
            'invoice' => $sale->statutoryInvoice?->invoice_number,
            'invoice_status' => $sale->statutoryInvoice?->status?->value,
            'serials' => $sale->serials->map(fn ($row) => [
                'id' => $row->serial_id,
                'serial_number' => $row->serial?->serial_number,
                'sku' => $row->serial?->product?->sku,
                'status' => $row->serial?->status?->value,
            ])->values()->all(),
        ];
    }
}
