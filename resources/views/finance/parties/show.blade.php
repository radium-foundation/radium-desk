@extends('layouts.app')

@section('title', $party->code)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('finance.parties.index') }}">Parties</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $party->code }}</li>
            </ol>
        </nav>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <h1 class="h3 mb-1">{{ $party->legal_name }}</h1>
                <p class="text-muted mb-0">
                    {{ $party->code }}
                    @foreach($party->roles as $role)
                        · {{ $role->role->label() }}
                    @endforeach
                    · {{ $party->is_active ? 'Active' : 'Inactive' }}
                </p>
            </div>
            @if($canManage)
                @if($party->is_active)
                    <form method="POST" action="{{ route('finance.parties.deactivate', $party) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-outline-secondary">Deactivate</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('finance.parties.activate', $party) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-outline-primary">Activate</button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'parties'])

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 text-uppercase text-muted mb-3">Basic information</h2>
            @if($canManage)
                <form method="POST" action="{{ route('finance.parties.update', $party) }}" class="row g-3">
                    @csrf
                    @method('PUT')
                    <div class="col-md-6">
                        <label class="form-label" for="legal_name">Legal name</label>
                        <input class="form-control" id="legal_name" name="legal_name" value="{{ old('legal_name', $party->legal_name) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="trade_name">Trade name</label>
                        <input class="form-control" id="trade_name" name="trade_name" value="{{ old('trade_name', $party->trade_name) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="kind">Type</label>
                        <select class="form-select" id="kind" name="kind">
                            @foreach(\App\Enums\FinancePartyKind::cases() as $kind)
                                <option value="{{ $kind->value }}" @selected(old('kind', $party->kind->value) === $kind->value)>{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control" id="phone" name="phone" value="{{ old('phone', $party->phone) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $party->email) }}">
                    </div>
                    <div class="col-12">
                        @foreach(\App\Enums\FinancePartyRoleType::cases() as $role)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="roles[]" id="show_role_{{ $role->value }}" value="{{ $role->value }}" @checked(in_array($role->value, old('roles', $party->roles->pluck('role')->map->value->all()), true))>
                                <label class="form-check-label" for="show_role_{{ $role->value }}">{{ $role->label() }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes', $party->notes) }}</textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm">Save identity</button>
                    </div>
                </form>
            @else
                <dl class="row mb-0">
                    <dt class="col-sm-3">Legal name</dt>
                    <dd class="col-sm-9">{{ $party->legal_name }}</dd>
                    <dt class="col-sm-3">Trade name</dt>
                    <dd class="col-sm-9">{{ $party->trade_name ?: '—' }}</dd>
                    <dt class="col-sm-3">Phone</dt>
                    <dd class="col-sm-9">{{ $party->phone ?: '—' }}</dd>
                    <dt class="col-sm-3">Email</dt>
                    <dd class="col-sm-9">{{ $party->email ?: '—' }}</dd>
                </dl>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 text-uppercase text-muted mb-3">Contacts</h2>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($party->contacts as $contact)
                            <tr>
                                <td>{{ $contact->name }} @if($contact->is_primary)<span class="badge text-bg-light border">Primary</span>@endif</td>
                                <td>{{ $contact->designation ?: '—' }}</td>
                                <td>{{ $contact->phone ?: '—' }}</td>
                                <td>{{ $contact->email ?: '—' }}</td>
                                <td class="text-muted small">{{ $contact->is_active ? '' : 'Inactive' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">No contacts.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($canManage)
                <form method="POST" action="{{ route('finance.parties.contacts.store', $party) }}" class="row g-2">
                    @csrf
                    <div class="col-md-3"><input class="form-control" name="name" placeholder="Name" required></div>
                    <div class="col-md-2"><input class="form-control" name="designation" placeholder="Designation"></div>
                    <div class="col-md-2"><input class="form-control" name="phone" placeholder="Phone"></div>
                    <div class="col-md-3"><input class="form-control" name="email" type="email" placeholder="Email"></div>
                    <div class="col-md-2 d-flex align-items-center gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="new_primary">
                            <label class="form-check-label" for="new_primary">Primary</label>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" type="submit">Add</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 text-uppercase text-muted mb-3">Addresses</h2>
            @forelse($party->addresses as $address)
                <p class="mb-2 small">
                    <strong>{{ $address->label ?: $address->kind->label() }}</strong>
                    · {{ $address->line1 }}{{ $address->line2 ? ', '.$address->line2 : '' }}
                    · {{ $address->city }} {{ $address->district }} {{ $address->state }} {{ $address->postal_code }}
                    @if($address->is_default_billing) · Default billing @endif
                    @if($address->is_default_shipping) · Default shipping @endif
                </p>
            @empty
                <p class="text-muted">No addresses.</p>
            @endforelse
            @if($canManage)
                <form method="POST" action="{{ route('finance.parties.addresses.store', $party) }}" class="row g-2 mt-2">
                    @csrf
                    <div class="col-md-2">
                        <input class="form-control" name="label" placeholder="Label">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="kind" required>
                            @foreach($addressKinds as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4"><input class="form-control" name="line1" placeholder="Address line 1" required></div>
                    <div class="col-md-2"><input class="form-control" name="city" placeholder="City"></div>
                    <div class="col-md-2"><input class="form-control" name="district" placeholder="District"></div>
                    <div class="col-md-3">
                        <select class="form-select" name="state" required>
                            <option value="">State</option>
                            @foreach($states as $state)
                                <option value="{{ $state }}">{{ $state }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2"><input class="form-control" name="postal_code" placeholder="PIN" required></div>
                    <div class="col-md-3"><input class="form-control" name="landmark" placeholder="Landmark"></div>
                    <div class="col-md-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default_billing" value="1" id="def_bill">
                            <label class="form-check-label" for="def_bill">Default billing</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default_shipping" value="1" id="def_ship">
                            <label class="form-check-label" for="def_ship">Default shipping</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Add address</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 text-uppercase text-muted mb-3">GST / statutory</h2>
            @forelse($party->gstRegistrations as $gst)
                <p class="mb-1 small">
                    <strong>{{ $gst->gstin }}</strong>
                    · {{ $gst->state }} ({{ $gst->state_code }})
                    @if($gst->pan) · PAN {{ $gst->pan }} @endif
                    @if($gst->is_primary) · Primary @endif
                    @if(! $gst->is_active) · Inactive @endif
                </p>
            @empty
                <p class="text-muted">No GST registrations. B2C parties can stay empty.</p>
            @endforelse
            @if($canManage)
                <form method="POST" action="{{ route('finance.parties.gst.store', $party) }}" class="row g-2 mt-2">
                    @csrf
                    <div class="col-md-3"><input class="form-control" name="gstin" placeholder="GSTIN" required></div>
                    <div class="col-md-3"><input class="form-control" name="registered_name" placeholder="Registered name"></div>
                    <div class="col-md-2"><input class="form-control" name="pan" placeholder="PAN (optional)"></div>
                    <div class="col-md-2 d-flex align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="gst_primary">
                            <label class="form-check-label" for="gst_primary">Primary</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Add GSTIN</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    @if($party->customerRole)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted mb-3">Customer terms</h2>
                @if($canManage)
                    <form method="POST" action="{{ route('finance.parties.customer-terms.update', $party) }}" class="row g-2">
                        @csrf
                        @method('PUT')
                        <div class="col-md-3"><input class="form-control" name="payment_terms" placeholder="Payment terms" value="{{ $party->customerRole->payment_terms }}"></div>
                        <div class="col-md-2"><input class="form-control" name="credit_days" type="number" min="0" placeholder="Credit days" value="{{ $party->customerRole->credit_days }}"></div>
                        <div class="col-md-2"><input class="form-control" name="credit_limit" type="number" step="0.01" min="0" placeholder="Credit limit" value="{{ $party->customerRole->credit_limit }}"></div>
                        <div class="col-md-3"><input class="form-control" name="preferred_payment_method" placeholder="Preferred payment" value="{{ $party->customerRole->preferred_payment_method }}"></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></div>
                    </form>
                @else
                    <p class="mb-0 small">{{ $party->customerRole->payment_terms ?: 'No terms yet.' }}</p>
                @endif
            </div>
        </div>
    @endif

    @if($party->vendorRole)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted mb-3">Vendor terms</h2>
                @if($canManage)
                    <form method="POST" action="{{ route('finance.parties.vendor-terms.update', $party) }}" class="row g-2">
                        @csrf
                        @method('PUT')
                        <div class="col-md-2"><input class="form-control" name="vendor_code" placeholder="Vendor code" value="{{ $party->vendorRole->vendor_code }}"></div>
                        <div class="col-md-3"><input class="form-control" name="payment_terms" placeholder="Payment terms" value="{{ $party->vendorRole->payment_terms }}"></div>
                        <div class="col-md-2"><input class="form-control" name="credit_days" type="number" min="0" placeholder="Credit days" value="{{ $party->vendorRole->credit_days }}"></div>
                        <div class="col-md-2"><input class="form-control" name="credit_limit" type="number" step="0.01" min="0" placeholder="Credit limit" value="{{ $party->vendorRole->credit_limit }}"></div>
                        <div class="col-md-2"><input class="form-control" name="preferred_payment_method" placeholder="Preferred payment" value="{{ $party->vendorRole->preferred_payment_method }}"></div>
                        <div class="col-md-1"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></div>
                    </form>
                @else
                    <p class="mb-0 small">{{ $party->vendorRole->vendor_code }} · {{ $party->vendorRole->payment_terms ?: 'No terms yet.' }}</p>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted mb-3">Vendor bank</h2>
                @forelse($party->vendorBankAccounts as $bank)
                    <p class="mb-1 small">
                        {{ $bank->bank_name }} · {{ $bank->account_holder_name }} · ****{{ $bank->last_four }}
                        @if($canViewBank)
                            · IFSC {{ $bank->getAttribute('ifsc') }}
                            · {{ $bank->getAttribute('account_number') }}
                        @endif
                        @if($bank->is_primary) · Primary @endif
                    </p>
                @empty
                    <p class="text-muted">No vendor bank accounts.</p>
                @endforelse
                @if($canManage && $canViewBank)
                    <form method="POST" action="{{ route('finance.parties.vendor-banks.store', $party) }}" class="row g-2 mt-2" autocomplete="off">
                        @csrf
                        <div class="col-md-2"><input class="form-control" name="bank_name" placeholder="Bank" required></div>
                        <div class="col-md-2"><input class="form-control" name="account_holder_name" placeholder="Holder" required></div>
                        <div class="col-md-3"><input class="form-control" name="account_number" placeholder="Account number" required autocomplete="off"></div>
                        <div class="col-md-2"><input class="form-control" name="ifsc" placeholder="IFSC" required></div>
                        <div class="col-md-2 d-flex align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="bank_primary">
                                <label class="form-check-label" for="bank_primary">Primary</label>
                            </div>
                        </div>
                        <div class="col-md-1"><button class="btn btn-sm btn-outline-primary" type="submit">Add</button></div>
                    </form>
                @elseif($canManage)
                    <p class="small text-muted mb-0">Vendor account numbers require the bank-view permission.</p>
                @endif
            </div>
        </div>
    @endif
@endsection
