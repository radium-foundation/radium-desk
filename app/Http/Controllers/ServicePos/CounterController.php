<?php

namespace App\Http\Controllers\ServicePos;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Services\ServicePos\ServiceQuoteService;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Support\Finance\IndianStates;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\ServicePos\ServiceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CounterController extends Controller
{
    public function __construct(
        private readonly ServiceQuoteService $quotes,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(ServiceAccess::allowsSell($request->user()), 403);

            return $next($request);
        });
    }

    public function create(Request $request): View
    {
        $branches = InventoryBranchScope::allowedBranches($request->user());
        $operatingBranch = $this->resolveOperatingBranch($request, $branches);

        return view('service-pos.counter.create', [
            'branches' => $branches,
            'operatingBranch' => $operatingBranch,
            'categories' => ServiceCategory::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
            'searchItemsUrl' => route('service-pos.items.search'),
            'lookupCustomerUrl' => route('pos.customers.lookup'),
            'placeOfSupplyStates' => IndianStates::names(),
        ]);
    }

    public function searchItems(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->toString();
        $categoryId = $request->integer('category_id');

        $items = ServiceItem::query()
            ->with('category')
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', '%'.$q.'%')
                        ->orWhere('name', 'like', '%'.$q.'%');
                });
            })
            ->when($categoryId > 0, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json([
            'items' => $items->map(fn (ServiceItem $item): array => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category?->name,
                'sac_code' => $item->sac_code,
                'gst_rate' => (float) $item->gst_rate,
                'price_ex_gst' => (float) $item->price_ex_gst,
                'duration_label' => $item->duration_label,
            ])->values()->all(),
        ]);
    }

    public function storeQuote(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:inventory_branches,id'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'buyer_gstin' => ['nullable', 'string', 'max:32'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'billing_state' => ['required', 'string', 'max:64', Rule::in(IndianStates::names())],
            'place_of_supply_state' => ['nullable', 'string', 'max:64', Rule::in(IndianStates::names())],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_item_id' => ['nullable', 'exists:service_items,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price_ex_gst' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.sac_code' => ['nullable', 'string', 'max:16'],
            'lines.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $branch = InventoryBranchScope::requireBranchId($data['branch_id'], $request->user());
        $customer = $this->findOrCreateCustomer($data);

        $lines = [];
        foreach ($data['lines'] as $line) {
            if (empty($line['service_item_id']) && empty($line['description'])) {
                continue;
            }
            $lines[] = $line;
        }

        $quote = $this->quotes->createQuote(
            customer: $customer,
            branch: $branch,
            lines: $lines,
            actor: $request->user(),
            billingAddress: $data['billing_address'] ?? null,
            billingState: $data['billing_state'],
            placeOfSupplyState: $data['place_of_supply_state'] ?? $data['billing_state'],
            buyerGstin: BuyerGstin::normalize($data['buyer_gstin'] ?? null),
            headerDiscount: (float) ($data['discount'] ?? 0),
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return redirect()
            ->route('service-pos.quotes.show', $quote)
            ->with('status', 'Internal proforma '.$quote->quote_number.' created. This is not a GST invoice.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findOrCreateCustomer(array $data): InventoryCustomer
    {
        $phone = trim((string) $data['customer_phone']);
        $customer = InventoryCustomer::query()->where('phone', $phone)->first();
        if ($customer === null) {
            return InventoryCustomer::query()->create([
                'name' => $data['customer_name'],
                'phone' => $phone,
                'email' => $data['customer_email'] ?? null,
                'gstin' => BuyerGstin::normalize($data['buyer_gstin'] ?? null),
            ]);
        }

        $customer->update([
            'name' => $data['customer_name'],
            'email' => $data['customer_email'] ?? $customer->email,
            'gstin' => BuyerGstin::normalize($data['buyer_gstin'] ?? null) ?? $customer->gstin,
        ]);

        return $customer->fresh() ?? $customer;
    }

    /**
     * @param  Collection<int, InventoryBranch>  $branches
     */
    private function resolveOperatingBranch(Request $request, $branches): ?InventoryBranch
    {
        $branchId = $request->integer('branch_id');
        if ($branchId > 0) {
            return $branches->firstWhere('id', $branchId);
        }

        $sessionId = $request->session()->get('service_pos.operating_branch_id');
        if ($sessionId) {
            return $branches->firstWhere('id', (int) $sessionId);
        }

        return $branches->count() === 1 ? $branches->first() : null;
    }
}
