<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\Purchasing\VendorService;
use App\Support\Purchasing\PurchasingAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function __construct(
        private readonly VendorService $vendors,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PurchasingAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();

        $vendors = Vendor::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('business_name', 'like', '%'.$search.'%')
                        ->orWhere('gstin', 'like', '%'.$search.'%')
                        ->orWhere('pan', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('legacy_supplier_id', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('is_active', $request->string('status')->toString() === 'active');
            })
            ->orderBy('business_name')
            ->paginate(30)
            ->withQueryString();

        return view('purchasing.vendors.index', [
            'vendors' => $vendors,
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        abort_unless(PurchasingAccess::allows($request->user()), 403);

        $q = $request->string('q')->trim()->toString();
        if ($q === '') {
            return response()->json(['vendors' => []]);
        }

        $vendors = Vendor::query()
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('business_name', 'like', '%'.$q.'%')
                    ->orWhere('gstin', 'like', '%'.$q.'%')
                    ->orWhere('phone', 'like', '%'.$q.'%');
            })
            ->orderBy('business_name')
            ->limit(20)
            ->get(['id', 'business_name', 'legal_name', 'gstin', 'phone']);

        return response()->json([
            'vendors' => $vendors->map(static fn (Vendor $vendor): array => [
                'id' => $vendor->id,
                'business_name' => $vendor->business_name,
                'legal_name' => $vendor->legal_name,
                'gstin' => $vendor->gstin,
                'phone' => $vendor->phone,
            ])->values(),
        ]);
    }

    public function create(): View
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE), 403);

        return view('purchasing.vendors.create');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE), 403);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'pan' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:64'],
            'pin' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $vendor = $this->vendors->create($validated, $request->user());

        return redirect()->route('purchasing.vendors.show', $vendor)->with('status', 'Vendor created.');
    }

    public function show(Vendor $vendor): View
    {
        return view('purchasing.vendors.show', ['vendor' => $vendor]);
    }

    public function edit(Vendor $vendor): View
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE), 403);

        return view('purchasing.vendors.edit', ['vendor' => $vendor]);
    }

    public function update(Request $request, Vendor $vendor): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE), 403);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'pan' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:64'],
            'pin' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->vendors->update($vendor, $validated, $request->user());

        return redirect()->route('purchasing.vendors.show', $vendor)->with('status', 'Vendor updated.');
    }

    public function toggle(Vendor $vendor): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE), 403);

        $this->vendors->setActive($vendor, ! $vendor->is_active, auth()->user());

        return back()->with('status', $vendor->is_active ? 'Vendor deactivated.' : 'Vendor activated.');
    }
}
