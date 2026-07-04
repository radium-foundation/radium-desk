@extends('layouts.customer')

@section('title', 'Schedule Technical Support')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h2 class="h5 fw-semibold mb-2">Schedule Technical Support</h2>
            <p class="text-muted small mb-4">
                Choose a convenient date and time for our team to call you and help with your device setup.
            </p>

            @if ($order)
                <div class="alert alert-light border small mb-4">
                    <div><strong>Service case:</strong> {{ $incident->display_reference }}</div>
                    @if (filled($order->customer_name))
                        <div><strong>Customer:</strong> {{ $order->customer_name }}</div>
                    @endif
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger small" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST"
                  action="{{ $formAction }}"
                  class="support-appointment-form"
                  data-support-appointment-form>
                @csrf

                <script type="application/json" data-support-appointment-availability>@json($availabilityConfig)</script>

                <div class="mb-3 support-appointment-date-field">
                    <label for="preferred_date" class="form-label">Preferred date</label>
                    <div class="input-group">
                        <input type="date"
                               id="preferred_date"
                               name="preferred_date"
                               class="form-control support-appointment-date-input @error('preferred_date') is-invalid @enderror"
                               value="{{ old('preferred_date') }}"
                               min="{{ now()->toDateString() }}"
                               placeholder="Select preferred date"
                               required>
                        <span class="input-group-text" aria-hidden="true">
                            <i class="bi bi-calendar3"></i>
                        </span>
                    </div>
                    @error('preferred_date')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="preferred_time_slot" class="form-label">Preferred time slot</label>
                    <select id="preferred_time_slot"
                            name="preferred_time_slot"
                            class="form-select @error('preferred_time_slot') is-invalid @enderror"
                            required>
                        <option value="" disabled {{ old('preferred_time_slot') ? '' : 'selected' }}>Select preferred time slot</option>
                        @foreach ($timeSlots as $slot)
                            @php
                                $isAvailableToday = in_array($slot, $todayAvailableSlots, true);
                            @endphp
                            <option value="{{ $slot->value }}"
                                    @selected(old('preferred_time_slot') === $slot->value)
                                    @if (! $isAvailableToday) data-unavailable-today="true" @endif>
                                {{ $slot->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('preferred_time_slot')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="phone_number" class="form-label">Phone number</label>
                    <input type="tel"
                           id="phone_number"
                           name="phone_number"
                           class="form-control @error('phone_number') is-invalid @enderror"
                           value="{{ old('phone_number', $order?->customer_phone) }}"
                           placeholder="e.g. 9876543210"
                           inputmode="tel"
                           autocomplete="tel"
                           required>
                    @error('phone_number')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="additional_notes" class="form-label">Additional notes <span class="text-muted">(optional)</span></label>
                    <textarea id="additional_notes"
                              name="additional_notes"
                              class="form-control @error('additional_notes') is-invalid @enderror"
                              rows="3"
                              maxlength="2000"
                              placeholder="Any specific issues or questions you'd like us to know about">{{ old('additional_notes') }}</textarea>
                    @error('additional_notes')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    Submit Appointment Request
                </button>
            </form>
        </div>
    </div>
@endsection
