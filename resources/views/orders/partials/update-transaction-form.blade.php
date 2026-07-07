@php
    $verificationService = app(\App\Services\CustomerVerificationService::class);
    $canCompleteService = $verificationService->canCompleteService($order);
    $requiresLegacyVerification = $verificationService->requiresLegacyVerification($order);
    $legacyVerificationMode = $verificationService->legacyVerificationMode($order);
@endphp

@can('assignTransaction', $order)
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Update Service Reference</h2>
        </div>
        <div class="card-body">
            @if(! $canCompleteService && ! $requiresLegacyVerification)
                <div class="alert alert-warning mb-0 py-2 small" role="alert">
                    Customer verification required before completing service.
                </div>
            @else
                <form method="POST"
                      action="{{ route('orders.transaction.store', $order) }}"
                      class="row g-3"
                      id="order-workspace-transaction-form"
                      data-order-workspace-transaction-form="true"
                      data-requires-legacy-verification="{{ $requiresLegacyVerification ? 'true' : 'false' }}"
                      data-legacy-verification-mode="{{ $legacyVerificationMode }}"
                      @if($requiresLegacyVerification)
                          data-legacy-verification-url="{{ route('orders.legacy-verification.store', $order) }}"
                      @endif>
                    @csrf
                    <div class="col-md-8">
                        <label for="transaction_id" class="form-label">Service Reference</label>
                        <input type="text"
                               name="transaction_id"
                               id="transaction_id"
                               class="form-control @error('transaction_id') is-invalid @enderror"
                               value="{{ old('transaction_id') }}"
                               placeholder="Enter service reference"
                               required>
                        @error('transaction_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check2-circle me-1"></i> Save Service Reference
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endcan

@can('unlockTransaction', $order)
    <div class="card border-0 shadow-sm mb-3 border-warning">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0 text-warning">Unlock Completed Order</h2>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                SuperAdmin only. This clears the service reference and reopens the order for editing.
            </p>
            <form method="POST"
                  action="{{ route('orders.transaction.destroy', $order) }}"
                  onsubmit="return confirm('Unlock this order and clear the service reference?');">
                @csrf
                @method('DELETE')
                <div class="mb-3">
                    <label for="unlock_reason" class="form-label">Reason <span class="text-muted">(optional)</span></label>
                    <textarea name="reason" id="unlock_reason" rows="2" class="form-control">{{ old('reason') }}</textarea>
                </div>
                <button type="submit" class="btn btn-outline-warning">
                    <i class="bi bi-unlock me-1"></i> Unlock Order
                </button>
            </form>
        </div>
    </div>
@endcan
