<div class="modal fade" id="quickCreateModal" tabindex="-1" aria-labelledby="quickCreateModalLabel"
     data-show-on-load="{{ ($errors->has('customer_id') || $errors->has('serial_number') || $errors->has('product') || $errors->has('notes')) ? 'true' : 'false' }}">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h5 mb-0" id="quickCreateModalLabel">Quick Create — New Service Request</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="{{ route('service-requests.quick.store') }}">
                @csrf
                <div class="modal-body py-3">
                    <p class="text-muted small mb-3">
                        Order ID and case reference are assigned automatically after submission.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="quick_customer_id" class="form-label">Customer ID <span class="text-danger">*</span></label>
                            <input type="text" name="customer_id" id="quick_customer_id"
                                   class="form-control @error('customer_id') is-invalid @enderror"
                                   value="{{ old('customer_id') }}"
                                   placeholder="Customer ID"
                                   required>
                            @error('customer_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="quick_serial_number" class="form-label">Serial Number <span class="text-danger">*</span></label>
                            <input type="text" name="serial_number" id="quick_serial_number"
                                   class="form-control @error('serial_number') is-invalid @enderror"
                                   value="{{ old('serial_number') }}"
                                   placeholder="Device serial number"
                                   required>
                            @error('serial_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12">
                            <label for="quick_product" class="form-label">Product <span class="text-danger">*</span></label>
                            <select name="product" id="quick_product"
                                    class="form-select @error('product') is-invalid @enderror"
                                    required>
                                <option value="" disabled @selected(old('product') === null)>Select product</option>
                                @foreach(config('products') as $productOption)
                                    <option value="{{ $productOption }}" @selected(old('product') === $productOption)>
                                        {{ $productOption }}
                                    </option>
                                @endforeach
                            </select>
                            @error('product')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12">
                            <label for="quick_notes" class="form-label">Notes <span class="text-danger">*</span></label>
                            <textarea name="notes" id="quick_notes" rows="3"
                                      class="form-control @error('notes') is-invalid @enderror"
                                      placeholder="Describe the issue or service request..."
                                      required>{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i> Create New Service Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
