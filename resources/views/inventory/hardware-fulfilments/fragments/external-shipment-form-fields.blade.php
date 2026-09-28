@php
    $catalog = $ready->externalCourierCatalog;
    $selectedCode = $ready->externalCourierCode ?? '';
    $formIdPrefix = $formIdPrefix ?? 'hardware-external';
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $formIdPrefix }}-courier-code">Courier</label>
    <select class="form-select"
            id="{{ $formIdPrefix }}-courier-code"
            name="courier_code"
            required
            data-hardware-external-courier-code>
        <option value="">Select courier</option>
        @foreach($catalog as $code => $label)
            <option value="{{ $code }}" @selected($selectedCode === $code)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="mb-3 {{ $selectedCode === 'other' ? '' : 'd-none' }}" data-hardware-external-other-wrap>
    <label class="form-label" for="{{ $formIdPrefix }}-courier-name">Courier name</label>
    <input class="form-control"
           id="{{ $formIdPrefix }}-courier-name"
           name="courier_name"
           maxlength="80">
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $formIdPrefix }}-awb">AWB / Tracking No.</label>
    <input class="form-control"
           id="{{ $formIdPrefix }}-awb"
           name="awb"
           maxlength="64"
           required>
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $formIdPrefix }}-tracking-url">Tracking URL (optional)</label>
    <input class="form-control"
           id="{{ $formIdPrefix }}-tracking-url"
           name="tracking_url"
           type="url"
           maxlength="1024"
           placeholder="https://">
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $formIdPrefix }}-label">Label upload (optional)</label>
    <input class="form-control"
           id="{{ $formIdPrefix }}-label"
           name="label"
           type="file"
           accept="application/pdf,image/jpeg,image/png">
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $formIdPrefix }}-manifest">Manifest upload (optional)</label>
    <input class="form-control"
           id="{{ $formIdPrefix }}-manifest"
           name="manifest"
           type="file"
           accept="application/pdf,image/jpeg,image/png">
</div>
<div class="mb-0">
    <label class="form-label" for="{{ $formIdPrefix }}-notes">Notes (optional)</label>
    <textarea class="form-control"
              id="{{ $formIdPrefix }}-notes"
              name="notes"
              rows="2"
              maxlength="2000">{{ $ready->externalNotes }}</textarea>
</div>
