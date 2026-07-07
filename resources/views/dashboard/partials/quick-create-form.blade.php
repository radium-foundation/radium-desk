<div class="modal fade" id="quickCreateModal" tabindex="-1" aria-labelledby="quickCreateModalLabel"
     data-show-on-load="{{ ($errors->has('phone') || $errors->has('order_id') || $errors->has('serial_number') || $errors->has('product') || $errors->has('source') || $errors->has('notes') || $errors->has('high_priority') || $errors->has('intent') || $errors->has('action') || ($reopenQuickCreate ?? false)) ? 'true' : 'false' }}"
     data-reset-on-show="{{ ($reopenQuickCreate ?? false) ? 'true' : 'false' }}"
     data-intake-search-url="{{ route('service-requests.intake.search') }}">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h5 mb-0" id="quickCreateModalLabel">New Service Request</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="{{ route('service-requests.quick.store') }}" id="customerIntakeForm">
                @csrf
                <input type="hidden" name="action" id="intake_action" value="{{ old('action', 'new_contact') }}">
                <input type="hidden" name="matched_order_id" id="intake_matched_order_id" value="{{ old('matched_order_id') }}">
                <input type="hidden" name="legacy_order_id" id="intake_legacy_order_id" value="{{ old('legacy_order_id') }}">

                <div class="modal-body py-3">
                    <div id="intake-step-search" class="intake-step">
                        <p class="text-muted small mb-3">
                            Search by phone number, order ID, or serial number. Customer identity is classified before creating any workflow.
                        </p>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="intake_phone" class="form-label">Phone Number</label>
                                <input type="text" name="phone" id="intake_phone"
                                       class="form-control @error('phone') is-invalid @enderror"
                                       value="{{ old('phone') }}"
                                       placeholder="Customer phone">
                                @error('phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label for="intake_order_id" class="form-label">Order ID</label>
                                <input type="text" id="intake_order_id"
                                       class="form-control @error('order_id') is-invalid @enderror"
                                       value="{{ old('order_id') }}"
                                       placeholder="Cashfree or legacy order ID">
                                @error('order_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label for="intake_serial_number" class="form-label">Serial Number</label>
                                <input type="text" name="serial_number" id="intake_serial_number"
                                       class="form-control @error('serial_number') is-invalid @enderror"
                                       value="{{ old('serial_number') }}"
                                       placeholder="Device serial">
                                @error('serial_number')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        @error('search')
                            <div class="alert alert-danger mt-3 mb-0 py-2 small">{{ $message }}</div>
                        @enderror

                        <div id="intake-search-feedback" class="alert d-none mt-3 mb-0 py-2 small" role="status"></div>
                    </div>

                    <div id="intake-step-results" class="intake-step d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="h6 mb-0">Matched Customers</h3>
                            <button type="button" class="btn btn-link btn-sm p-0" data-intake-back="search">Change search</button>
                        </div>
                        <p class="text-muted small mb-2" id="intake-classification-label"></p>
                        <div id="intake-matches-list" class="list-group mb-3"></div>
                    </div>

                    <div id="intake-step-legacy-preview" class="intake-step d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="h6 mb-0">Legacy Order Preview</h3>
                            <button type="button" class="btn btn-link btn-sm p-0" data-intake-back="search">Change search</button>
                        </div>
                        <p class="alert alert-info py-2 small mb-3" id="intake-legacy-preview-message"></p>
                        <dl class="row small mb-3" id="intake-legacy-preview-fields"></dl>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-intake-back="search">Cancel</button>
                            <button type="button" class="btn btn-primary btn-sm" id="intake-legacy-confirm-button">Confirm &amp; Continue</button>
                        </div>
                    </div>

                    <div id="intake-step-new-contact" class="intake-step d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="h6 mb-0">New Contact</h3>
                            <button type="button" class="btn btn-link btn-sm p-0" data-intake-back="search">Change search</button>
                        </div>
                        <p class="text-muted small mb-3">No matching customer or order was found. Capture intent before creating a workflow.</p>

                        <fieldset class="mb-3">
                            <legend class="form-label fs-6">Customer Intent <span class="text-danger">*</span></legend>
                            @foreach(\App\Enums\NewContactIntent::cases() as $intentOption)
                                <div class="form-check">
                                    <input type="radio"
                                           name="intent"
                                           value="{{ $intentOption->value }}"
                                           id="intent_{{ $intentOption->value }}"
                                           class="form-check-input @error('intent') is-invalid @enderror"
                                           @checked(old('intent') === $intentOption->value)>
                                    <label class="form-check-label" for="intent_{{ $intentOption->value }}">
                                        {{ $intentOption->label() }}
                                    </label>
                                </div>
                            @endforeach
                            @error('intent')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </fieldset>
                    </div>

                    <div id="intake-step-details" class="intake-step d-none">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="intake_product" class="form-label">Product</label>
                                <select name="product" id="intake_product"
                                        class="form-select @error('product') is-invalid @enderror">
                                    <option value="">Select product</option>
                                    @foreach(($enabledProducts ?? []) as $productOption)
                                        <option value="{{ $productOption }}" @selected(old('product') === $productOption)>
                                            {{ $productOption }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('product')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="intake_source" class="form-label">Source <span class="text-danger">*</span></label>
                                <select name="source" id="intake_source"
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
                                           id="intake_high_priority"
                                           class="form-check-input @error('high_priority') is-invalid @enderror"
                                           @checked(old('high_priority'))>
                                    <label class="form-check-label" for="intake_high_priority">
                                        High Priority
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="intake_notes" class="form-label">Comment / Issue Description <span class="text-danger">*</span></label>
                                <textarea name="notes" id="intake_notes" rows="3"
                                          class="form-control @error('notes') is-invalid @enderror"
                                          required
                                          placeholder="Describe the issue or service request...">{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="intake-search-button">
                        <i class="bi bi-search me-1"></i> Search Customer
                    </button>
                    <button type="submit" class="btn btn-primary d-none" id="intake-submit-button">
                        <i class="bi bi-plus-circle me-1"></i> Create Service Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('partials.legacy-verification-modal')
