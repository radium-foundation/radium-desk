@props(['order', 'showStatus' => false])

@php
    use App\Support\DeviceModelFormatter;

    $formattedDeviceModel = DeviceModelFormatter::shortDisplay(
        $order->displayDeviceModelName() ?? $order->device_model,
    );
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <label for="order_id" class="form-label">Order ID <span class="text-danger">*</span></label>
        <input type="text" name="order_id" id="order_id"
               class="form-control @error('order_id') is-invalid @enderror"
               value="{{ old('order_id', $order->order_id) }}" required>
        @error('order_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="serial_number" class="form-label">Serial Number <span class="text-danger">*</span></label>
        <input type="text" name="serial_number" id="serial_number"
               class="form-control @error('serial_number') is-invalid @enderror"
               value="{{ old('serial_number', $order->serial_number) }}" required>
        @error('serial_number')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="product_name" class="form-label">Product Name <span class="text-danger">*</span></label>
        <input type="text" name="product_name" id="product_name"
               class="form-control @error('product_name') is-invalid @enderror"
               value="{{ old('product_name', $order->product_name) }}" required>
        @error('product_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">Canonical value stored on the order.</div>
    </div>

    <div class="col-md-6">
        <label for="order_device_model_search" class="form-label">Device Model <span class="text-danger">*</span></label>
        <input type="search"
               id="order_device_model_search"
               class="form-control form-control-sm mb-2"
               placeholder="Search models..."
               autocomplete="off"
               data-device-model-search
               data-device-model-select="order_device_model_select">
        @include('dashboard.partials.device-model-select', [
            'selectId' => 'order_device_model_select',
            'fieldName' => 'device_model_id',
            'deviceModels' => $deviceModels ?? [],
            'selected' => old('device_model_id', $order->device_model_id),
            'showLabel' => false,
            'hasError' => $errors->has('device_model_id'),
        ])
        @if($formattedDeviceModel)
            <div class="form-text">Dashboard display: {{ $formattedDeviceModel }}</div>
        @endif
        @if(filled($order->device_model) && ! $order->device_model_id)
            <div class="form-text">Legacy free-text model: {{ $order->device_model }}</div>
        @endif
    </div>

    @once
        @push('scripts')
            <script>
                document.querySelectorAll('[data-device-model-search]').forEach((searchInput) => {
                    const select = document.getElementById(searchInput.dataset.deviceModelSelect);

                    if (!select) {
                        return;
                    }

                    const options = Array.from(select.options).slice(1);

                    searchInput.addEventListener('input', () => {
                        const term = searchInput.value.trim().toLowerCase();

                        options.forEach((option) => {
                            const visible = term === '' || option.text.toLowerCase().includes(term);
                            option.hidden = !visible;
                            option.disabled = !visible;
                        });
                    });
                });
            </script>
        @endpush
    @endonce

    @if($showStatus)
        <div class="col-md-6">
            <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
            <select name="status" id="status" class="form-select @error('status') is-invalid @enderror" required>
                @foreach(\App\Enums\OrderStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $order->status?->value) === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>
            @error('status')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    @endif

    <div class="col-md-6">
        <label for="customer_name" class="form-label">Customer Name</label>
        <input type="text" name="customer_name" id="customer_name"
               class="form-control @error('customer_name') is-invalid @enderror"
               value="{{ old('customer_name', $order->customer_name) }}">
        @error('customer_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="customer_email" class="form-label">Customer Email</label>
        <input type="email" name="customer_email" id="customer_email"
               class="form-control @error('customer_email') is-invalid @enderror"
               value="{{ old('customer_email', $order->customer_email) }}">
        @error('customer_email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="customer_phone" class="form-label">Customer Phone</label>
        <input type="text" name="customer_phone" id="customer_phone"
               class="form-control @error('customer_phone') is-invalid @enderror"
               value="{{ old('customer_phone', $order->customer_phone) }}">
        @error('customer_phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
