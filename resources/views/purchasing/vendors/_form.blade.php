@php($vendor = $vendor ?? null)

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Business name</label>
        <input type="text" name="business_name" class="form-control" value="{{ old('business_name', $vendor?->business_name) }}" required>
    </div>
    <div class="col-md-6">
        <label class="form-label">Legal / trade name</label>
        <input type="text" name="legal_name" class="form-control" value="{{ old('legal_name', $vendor?->legal_name) }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">GSTIN</label>
        <input type="text" name="gstin" class="form-control" value="{{ old('gstin', $vendor?->gstin) }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">PAN</label>
        <input type="text" name="pan" class="form-control" value="{{ old('pan', $vendor?->pan) }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="{{ old('phone', $vendor?->phone) }}">
    </div>
    <div class="col-md-6">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="{{ old('email', $vendor?->email) }}">
    </div>
    <div class="col-md-6">
        <label class="form-label">PIN</label>
        <input type="text" name="pin" class="form-control" value="{{ old('pin', $vendor?->pin) }}">
    </div>
    <div class="col-md-12">
        <label class="form-label">Billing address</label>
        <textarea name="billing_address" class="form-control" rows="2">{{ old('billing_address', $vendor?->billing_address) }}</textarea>
    </div>
    <div class="col-md-4">
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control" value="{{ old('city', $vendor?->city) }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">State</label>
        <input type="text" name="state" class="form-control" value="{{ old('state', $vendor?->state) }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">Country</label>
        <input type="text" name="country" class="form-control" value="{{ old('country', $vendor?->country ?? 'India') }}">
    </div>
    <div class="col-md-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2">{{ old('notes', $vendor?->notes) }}</textarea>
    </div>
    <div class="col-md-4">
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $vendor?->is_active ?? true))>
            <label class="form-check-label">Active</label>
        </div>
    </div>
</div>
