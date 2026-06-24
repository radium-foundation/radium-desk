@props(['order', 'showStatus' => false])

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
    </div>

    <div class="col-md-6">
        <label for="device_model" class="form-label">Device Model <span class="text-danger">*</span></label>
        <input type="text" name="device_model" id="device_model"
               class="form-control @error('device_model') is-invalid @enderror"
               value="{{ old('device_model', $order->device_model) }}" required>
        @error('device_model')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

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
