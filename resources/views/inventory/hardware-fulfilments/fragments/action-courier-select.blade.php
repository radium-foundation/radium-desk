<form method="POST"
      action="{{ route('inventory.hardware-fulfilments.courier.store', $fulfilment) }}"
      data-hardware-action-form
      id="{{ $formId }}">
    @csrf
    <x-c360.section-card title="Returned services" class="mb-2">
        @if($ready->recommendationNote !== '')
            <p class="small mb-2 {{ $ready->recommendationReturned ? 'text-success' : 'text-muted' }}">{{ $ready->recommendationNote }}</p>
        @endif
        @foreach($ready->courierOptions as $option)
            @php
                $optionId = (string) ($option['courier_id'] ?? '');
                $optionLabel = $option['courier_name'] ?? $optionId;
                if (! empty($option['courier_type'])) {
                    $optionLabel .= ' · '.$option['courier_type'];
                }
                if (! empty($option['mode'])) {
                    $optionLabel .= ' · '.$option['mode'];
                }
                if (($option['rate'] ?? null) !== null) {
                    $optionLabel .= ' · '.$option['rate'];
                }
                $recommended = ! empty($option['provider_recommended']);
            @endphp
            <div class="form-check mb-2 @if($recommended) hardware-action-courier--recommended @endif">
                <input class="form-check-input"
                       type="radio"
                       name="courier_id"
                       id="hardware-action-courier-{{ $formId }}-{{ $optionId }}"
                       value="{{ $optionId }}"
                       required
                       @checked($ready->selectedCourierId === $optionId)>
                <label class="form-check-label small" for="hardware-action-courier-{{ $formId }}-{{ $optionId }}">
                    {{ $optionLabel }}
                    @if($recommended)
                        <span class="text-success"> · Recommended</span>
                    @endif
                </label>
            </div>
        @endforeach
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
            {{ $submitLabel }}
        </button>
    </x-c360.modal-footer>
</form>
