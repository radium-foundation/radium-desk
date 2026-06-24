@props(['incident', 'showStatus' => true, 'selectedOrder' => null])

<div class="row g-3">
    @include('incidents.partials.order-select', [
        'selectedOrder' => $selectedOrder ?? $incident->order,
        'orderIdValue' => old('order_id', $incident->order_id ?? $selectedOrder?->id),
    ])

    <div class="col-md-6">
        <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
        <input type="text" name="category" id="category" list="incident_categories"
               class="form-control @error('category') is-invalid @enderror"
               value="{{ old('category', $incident->category) }}" required>
        <datalist id="incident_categories">
            @foreach(($categories ?? collect()) as $category)
                <option value="{{ $category }}"></option>
            @endforeach
        </datalist>
        @error('category')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="source" class="form-label">Source <span class="text-danger">*</span></label>
        <select name="source" id="source" class="form-select @error('source') is-invalid @enderror" required>
            @foreach(\App\Enums\IncidentSource::cases() as $source)
                <option value="{{ $source->value }}" @selected(old('source', $incident->source?->value ?? 'internal') === $source->value)>
                    {{ $source->label() }}
                </option>
            @endforeach
        </select>
        @error('source')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    @if($showStatus)
        <div class="col-md-6">
            <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
            <select name="status" id="status" class="form-select @error('status') is-invalid @enderror" required>
                @foreach(\App\Enums\IncidentStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $incident->status?->value ?? 'open') === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>
            @error('status')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    @endif

    <div class="col-12">
        <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" id="title"
               class="form-control @error('title') is-invalid @enderror"
               value="{{ old('title', $incident->title) }}" required>
        @error('title')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
        <textarea name="description" id="description" rows="5"
                  class="form-control @error('description') is-invalid @enderror" required>{{ old('description', $incident->description) }}</textarea>
        @error('description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
