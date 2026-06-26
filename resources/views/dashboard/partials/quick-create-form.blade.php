<div class="modal fade" id="quickCreateModal" tabindex="-1" aria-labelledby="quickCreateModalLabel"
     data-show-on-load="{{ ($errors->has('order_id') || $errors->has('serial_number') || $errors->has('product') || $errors->has('source') || $errors->has('notes') || $errors->has('high_priority') || ($reopenQuickCreate ?? false)) ? 'true' : 'false' }}"
     data-reset-on-show="{{ ($reopenQuickCreate ?? false) ? 'true' : 'false' }}">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h5 mb-0" id="quickCreateModalLabel">Quick Create — Search Order</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="{{ route('service-requests.quick.store') }}">
                @csrf
                <div class="modal-body py-3">
                    <p class="text-muted small mb-3">
                        Search by order ID. Existing orders open the order hub. New orders create the first service case automatically.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="quick_order_id" class="form-label">Order ID <span class="text-danger">*</span></label>
                            <input type="text" name="order_id" id="quick_order_id"
                                   class="form-control @error('order_id') is-invalid @enderror"
                                   value="{{ old('order_id') }}"
                                   placeholder="Order ID"
                                   required>
                            @error('order_id')
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

                        <div class="col-md-6">
                            <label for="quick_product" class="form-label">Product <span class="text-danger">*</span></label>
                            <select name="product" id="quick_product"
                                    class="form-select @error('product') is-invalid @enderror"
                                    required>
                                @foreach(($enabledProducts ?? []) as $productOption)
                                    <option value="{{ $productOption }}" @selected(old('product', $enabledProducts[0] ?? '') === $productOption)>
                                        {{ $productOption }}
                                    </option>
                                @endforeach
                            </select>
                            @error('product')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="quick_source" class="form-label">Source <span class="text-danger">*</span></label>
                            <select name="source" id="quick_source"
                                    class="form-select @error('source') is-invalid @enderror"
                                    required>
                                <option value="" disabled @selected(old('source') === null)>Select source</option>
                                @foreach(($enabledSources ?? collect()) as $sourceOption)
                                    <option value="{{ $sourceOption->key }}" @selected(old('source', $enabledSources->first()?->key) === $sourceOption->key)>
                                        {{ $sourceOption->label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('source')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox"
                                       name="high_priority"
                                       value="1"
                                       id="quick_high_priority"
                                       class="form-check-input @error('high_priority') is-invalid @enderror"
                                       @checked(old('high_priority'))>
                                <label class="form-check-label" for="quick_high_priority">
                                    High Priority
                                </label>
                                @error('high_priority')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="quick_notes" class="form-label">Comment / Notes</label>
                            <textarea name="notes" id="quick_notes" rows="3"
                                      class="form-control @error('notes') is-invalid @enderror"
                                      placeholder="Describe the issue or service request (optional)...">{{ old('notes') }}</textarea>
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
