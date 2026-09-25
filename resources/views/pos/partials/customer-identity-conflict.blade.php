@php
    $conflict = session('pos_customer_identity_conflict');
    $showConflict = $errors->has('customer_identity_conflict') && is_array($conflict);
@endphp
@if($showConflict)
    <div class="alert alert-warning border-warning">
        <h2 class="h6 mb-2">Customer identity conflict</h2>
        <p class="mb-2">{{ $errors->first('customer_identity_conflict') }}</p>
        <div class="row g-3 small mb-3">
            <div class="col-md-6">
                <div class="fw-semibold text-uppercase">Customer master (current)</div>
                <div>{{ $conflict['existing_name'] }}</div>
                <div>{{ $conflict['existing_gstin'] ? 'GSTIN '.$conflict['existing_gstin'] : 'No GSTIN on master' }}</div>
            </div>
            <div class="col-md-6">
                <div class="fw-semibold text-uppercase">This sale</div>
                <div>{{ $conflict['incoming_name'] }}</div>
                <div>{{ $conflict['incoming_gstin'] ? 'GSTIN '.$conflict['incoming_gstin'] : 'No GSTIN entered' }}</div>
            </div>
        </div>
        <p class="small mb-2">These may be different businesses sharing the same phone number. Choose one option before completing the sale.</p>
        <div class="vstack gap-2">
            <label class="d-flex gap-2 align-items-start">
                <input type="radio" name="customer_identity_resolution" value="sale_only" class="mt-1" @checked(old('customer_identity_resolution') === 'sale_only') required>
                <span>
                    <strong>Use this sale identity only.</strong>
                    Keep the customer master unchanged and store the entered company/GSTIN on this sale.
                </span>
            </label>
            <label class="d-flex gap-2 align-items-start">
                <input type="radio" name="customer_identity_resolution" value="update_master" class="mt-1" @checked(old('customer_identity_resolution') === 'update_master')>
                <span>
                    <strong>Update the customer master.</strong>
                    Replace the stored customer master legal identity with the values entered for this sale.
                </span>
            </label>
        </div>
        @error('customer_identity_resolution')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
    </div>
@endif
