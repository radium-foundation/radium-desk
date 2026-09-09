<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinancePartyAddressKind;
use App\Enums\FinancePartyKind;
use App\Enums\FinancePartyRoleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancePartyAddressRequest;
use App\Http\Requests\Finance\StoreFinancePartyContactRequest;
use App\Http\Requests\Finance\StoreFinancePartyGstRequest;
use App\Http\Requests\Finance\StoreFinancePartyRequest;
use App\Http\Requests\Finance\StoreFinancePartyVendorBankRequest;
use App\Http\Requests\Finance\UpdateFinancePartyRequest;
use App\Http\Requests\Finance\UpdateFinancePartyTermsRequest;
use App\Models\FinanceParty;
use App\Models\FinancePartyAddress;
use App\Models\FinancePartyContact;
use App\Models\FinancePartyGstRegistration;
use App\Services\Finance\PartyMasterService;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartyController extends Controller
{
    public function __construct(
        private readonly PartyMasterService $parties,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(FinanceAccess::allowsParties($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();
        $role = $request->string('role')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        $parties = FinanceParty::query()
            ->with(['roles', 'primaryContact', 'primaryGstRegistration'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('code', 'like', $like)
                        ->orWhere('legal_name', 'like', $like)
                        ->orWhere('trade_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereHas('gstRegistrations', fn ($gst) => $gst->where('gstin', 'like', $like))
                        ->orWhereHas('roles', fn ($roles) => $roles->where('vendor_code', 'like', $like));
                });
            })
            ->when($role === FinancePartyRoleType::Customer->value, function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->where('role', FinancePartyRoleType::Customer->value));
            })
            ->when($role === FinancePartyRoleType::Vendor->value, function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->where('role', FinancePartyRoleType::Vendor->value));
            })
            ->when($role === 'both', function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->where('role', FinancePartyRoleType::Customer->value))
                    ->whereHas('roles', fn ($roles) => $roles->where('role', FinancePartyRoleType::Vendor->value));
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('legal_name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('finance.parties.index', [
            'parties' => $parties,
            'filters' => $request->only(['q', 'role', 'status']),
            'canManage' => FinanceAccess::allowsPartyManage($request->user()),
        ]);
    }

    public function create(): View
    {
        abort_unless(FinanceAccess::allowsPartyManage(request()->user()), 403);

        return view('finance.parties.create', $this->formOptions());
    }

    public function store(StoreFinancePartyRequest $request): RedirectResponse
    {
        $party = $this->parties->create($request->validated(), $request->validated('roles'));

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-created');
    }

    public function show(Request $request, FinanceParty $party): View
    {
        $party->load([
            'roles',
            'customerRole',
            'vendorRole',
            'addresses',
            'contacts',
            'gstRegistrations',
            'vendorBankAccounts',
        ]);

        return view('finance.parties.show', [
            'party' => $party,
            'canManage' => FinanceAccess::allowsPartyManage($request->user()),
            'canViewBank' => FinanceAccess::allowsPartyBank($request->user()),
            'states' => IndianStates::names(),
            'addressKinds' => FinancePartyAddressKind::cases(),
        ]);
    }

    public function update(UpdateFinancePartyRequest $request, FinanceParty $party): RedirectResponse
    {
        $this->parties->updateIdentity($party, $request->validated(), $request->validated('roles'));

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-updated');
    }

    public function deactivate(FinanceParty $party): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsPartyManage(request()->user()), 403);

        $this->parties->deactivate($party);

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-deactivated');
    }

    public function activate(FinanceParty $party): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsPartyManage(request()->user()), 403);

        $this->parties->activate($party);

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-activated');
    }

    public function storeAddress(StoreFinancePartyAddressRequest $request, FinanceParty $party): RedirectResponse
    {
        $this->parties->saveAddress($party, $request->validated());

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-address-saved');
    }

    public function updateAddress(StoreFinancePartyAddressRequest $request, FinanceParty $party, FinancePartyAddress $address): RedirectResponse
    {
        abort_unless($address->party_id === $party->id, 404);

        $this->parties->saveAddress($party, $request->validated(), $address);

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-address-saved');
    }

    public function storeContact(StoreFinancePartyContactRequest $request, FinanceParty $party): RedirectResponse
    {
        $this->parties->saveContact($party, $request->validated());

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-contact-saved');
    }

    public function updateContact(StoreFinancePartyContactRequest $request, FinanceParty $party, FinancePartyContact $contact): RedirectResponse
    {
        abort_unless($contact->party_id === $party->id, 404);

        $this->parties->saveContact($party, $request->validated(), $contact);

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-contact-saved');
    }

    public function storeGst(StoreFinancePartyGstRequest $request, FinanceParty $party): RedirectResponse
    {
        $this->parties->saveGstRegistration($party, $request->validated());

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-gst-saved');
    }

    public function updateGst(StoreFinancePartyGstRequest $request, FinanceParty $party, FinancePartyGstRegistration $gst): RedirectResponse
    {
        abort_unless($gst->party_id === $party->id, 404);

        $this->parties->saveGstRegistration($party, $request->validated(), $gst);

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-gst-saved');
    }

    public function updateCustomerTerms(UpdateFinancePartyTermsRequest $request, FinanceParty $party): RedirectResponse
    {
        $this->parties->saveCustomerTerms($party, $request->validated());

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-terms-saved');
    }

    public function updateVendorTerms(UpdateFinancePartyTermsRequest $request, FinanceParty $party): RedirectResponse
    {
        $this->parties->saveVendorTerms($party, $request->validated());

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-terms-saved');
    }

    public function storeVendorBank(StoreFinancePartyVendorBankRequest $request, FinanceParty $party): RedirectResponse
    {
        $this->parties->saveVendorBankAccount($party, $request->validated());

        return redirect()
            ->route('finance.parties.show', $party)
            ->with('status', 'finance-party-bank-saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'kinds' => FinancePartyKind::cases(),
            'roleTypes' => FinancePartyRoleType::cases(),
        ];
    }
}
