@if(($ready->labelUrl ?? null) || ($ready->manifestUrl ?? null))
    <div class="d-flex flex-wrap gap-2 {{ $wrapperClass ?? 'mb-2' }}" data-hardware-document-downloads>
        @if($ready->labelUrl)
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('inventory.hardware-fulfilments.label.download', $fulfilment) }}"
               id="{{ $labelId ?? 'hardware-label-download' }}">Download Label</a>
        @endif
        @if($ready->manifestUrl)
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('inventory.hardware-fulfilments.manifest.download', $fulfilment) }}"
               id="{{ $manifestId ?? 'hardware-manifest-download' }}">Download Manifest</a>
        @endif
    </div>
@endif
