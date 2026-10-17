<?php

namespace App\Http\Controllers;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Http\Requests\InspectReturnExchangeCaseReceiptRequest;
use App\Http\Requests\ReceiveReturnExchangeCaseRequest;
use App\Http\Requests\StoreReturnExchangeCaseRequest;
use App\Models\ReturnExchangeCase;
use App\Models\ReturnExchangeCaseReceipt;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReturnExchangeCaseController extends Controller
{
    public function __construct(
        private readonly ReturnExchangeCaseIntakeService $intakeService,
        private readonly ReturnExchangeCaseGoodsReceiptService $goodsReceiptService,
    ) {
        $this->authorizeResource(ReturnExchangeCase::class, 'return_case', [
            'except' => ['edit', 'update', 'destroy'],
        ]);
    }

    public function index(Request $request): View
    {
        $query = ReturnExchangeCase::query()
            ->with(['requester', 'branch'])
            ->orderByDesc('id');

        ReturnExchangeCaseBranchAccess::constrainVisible($query, $request->user());

        return view('return-cases.index', [
            'cases' => $query->paginate(25)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('return-cases.create', [
            'sourceKinds' => ReturnExchangeCaseSourceKind::cases(),
        ]);
    }

    public function store(StoreReturnExchangeCaseRequest $request): RedirectResponse
    {
        $case = $this->intakeService->create($request->user(), $request->validated());

        return redirect()
            ->route('return-cases.show', $case)
            ->with('status', 'Return case '.$case->case_no.' recorded for internal review.');
    }

    public function show(Request $request, ReturnExchangeCase $returnCase): View
    {
        $returnCase->load([
            'lines',
            'requester',
            'branch',
            'inventorySale',
            'order',
            'hardwareFulfilment',
            'receipts.line',
            'receipts.serial',
            'receipts.receiver',
            'receipts.inspector',
        ]);

        $user = $request->user();

        return view('return-cases.show', [
            'case' => $returnCase,
            'canReceive' => $user !== null && $user->can('receive', $returnCase),
            'canInspect' => $user !== null && $user->can('inspect', $returnCase),
        ]);
    }

    public function receive(ReceiveReturnExchangeCaseRequest $request, ReturnExchangeCase $returnCase): RedirectResponse
    {
        $this->goodsReceiptService->receive($returnCase, $request->user(), $request->validated());

        return redirect()
            ->route('return-cases.show', $returnCase)
            ->with('status', 'Goods receipt recorded for '.$returnCase->case_no.'.');
    }

    public function inspect(
        InspectReturnExchangeCaseReceiptRequest $request,
        ReturnExchangeCase $returnCase,
        ReturnExchangeCaseReceipt $receipt,
    ): RedirectResponse {
        $this->goodsReceiptService->inspect($returnCase, $receipt, $request->user(), $request->validated());

        return redirect()
            ->route('return-cases.show', $returnCase)
            ->with('status', 'Inspection recorded for '.$returnCase->case_no.'.');
    }
}
