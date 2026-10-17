@php
    $replacementRecorded = $case->serialReplacements->isNotEmpty();
@endphp

@if ($case->status === \App\Enums\ReturnExchangeCaseStatus::Inspected && ($canPerformSerialReplacement ?? false))
    <div class="card mb-3 border-primary">
        <div class="card-header py-2">
            <span class="small fw-semibold">Equal-value serial replacement</span>
        </div>
        <div class="card-body small">
            <p class="text-muted mb-2">
                Same product and verified unit value only. Does not change the original invoice or sale serial assignment.
                Online fulfilment replacements are not available in this release.
            </p>

            @if ($replacementRecorded)
                <ul class="list-unstyled mb-0">
                    @foreach ($case->serialReplacements as $row)
                        <li class="mb-2">
                            <span class="fw-semibold">{{ $row->returnedSerial?->serial_number }}</span>
                            →
                            <span class="fw-semibold">{{ $row->replacementSerial?->serial_number }}</span>
                            <span class="text-muted">({{ $row->performed_at?->format('d M Y H:i') }})</span>
                        </li>
                    @endforeach
                </ul>
            @else
                @foreach ($case->receipts as $receipt)
                    @if ($receipt->inspected_at && $receipt->inventory_serial_id && ! $receipt->repair_hold && $receipt->serialReplacement === null)
                        <form method="post" action="{{ route('return-cases.serial-replacement.store', $case) }}" class="border rounded p-2 mb-2">
                            @csrf
                            <input type="hidden" name="return_exchange_case_receipt_id" value="{{ $receipt->id }}">
                            <label class="form-label mb-1" for="replacement_serial_{{ $receipt->id }}">
                                Replacement serial (returned: {{ $receipt->serial?->serial_number ?? $receipt->serial_number_scanned }})
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="text"
                                       name="replacement_serial_number"
                                       id="replacement_serial_{{ $receipt->id }}"
                                       class="form-control @error('replacement_serial_number') is-invalid @enderror"
                                       required
                                       autocomplete="off">
                                <button type="submit" class="btn btn-primary">Record replacement</button>
                            </div>
                            @error('replacement')
                                <div class="text-danger mt-1">{{ $message }}</div>
                            @enderror
                            @error('replacement_serial_number')
                                <div class="text-danger mt-1">{{ $message }}</div>
                            @enderror
                            @error('tax_evidence')
                                <div class="text-danger mt-1">{{ $message }}</div>
                            @enderror
                        </form>
                    @endif
                @endforeach
            @endif
        </div>
    </div>
@endif
