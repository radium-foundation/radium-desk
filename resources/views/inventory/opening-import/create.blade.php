@extends('layouts.app')

@section('title', 'Opening inventory import')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Opening inventory import</h1>
        <p class="text-muted mb-0">Preview the agreed Excel template first. Apply only after every row is clean. Import does not create branches or invent quantities.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'opening-import'])

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.opening-import.preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label" for="workbook">Opening inventory workbook (.xlsx)</label>
                        <input id="workbook" type="file" name="workbook" class="form-control" accept=".xlsx" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-outline-primary">Preview only</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if($result)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Preview</h2>
                <p class="mb-1">Checksum: <code>{{ $result->batch->source_checksum }}</code></p>
                <p class="mb-1">Opening rows: {{ $result->openingRows }} · Valid: {{ $result->validRows }} · Invalid: {{ $result->invalidRows }} · SKU Master: {{ $result->skuRows }}</p>
                <p class="mb-1">Reconciliation (valid rows only): available serials {{ $result->reconciliation->availableSerials }} · damaged serials {{ $result->reconciliation->damagedSerials }} · quantity units {{ $result->reconciliation->quantityUnits }}</p>
                <p class="mb-0">
                    @if($result->alreadyApplied)
                        This workbook was already applied. Stock will not change again.
                    @elseif($result->canApply)
                        Preview is clean. Apply will add stock in one transaction.
                    @else
                        Preview is blocked. Fix every error. Stock was not changed.
                    @endif
                </p>
            </div>
        </div>

        @if($result->reconciliation->byBranch !== [] || $result->reconciliation->bySku !== [])
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5">Reconciliation by branch / SKU</h2>
                    <p class="text-muted small">Compare these totals to the physical-count sheet before apply. Invalid rows are excluded.</p>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    <th>Available serials</th>
                                    <th>Damaged serials</th>
                                    <th>Quantity units</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($result->reconciliation->byBranch as $line)
                                    <tr>
                                        <td>{{ $line['branch'] }}</td>
                                        <td>{{ $line['available_serials'] }}</td>
                                        <td>{{ $line['damaged_serials'] }}</td>
                                        <td>{{ $line['quantity_units'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Available serials</th>
                                    <th>Damaged serials</th>
                                    <th>Quantity units</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($result->reconciliation->bySku as $line)
                                    <tr>
                                        <td>{{ $line['sku'] }}</td>
                                        <td>{{ $line['available_serials'] }}</td>
                                        <td>{{ $line['damaged_serials'] }}</td>
                                        <td>{{ $line['quantity_units'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        @if($result->issues !== [])
            <div class="card border-0 shadow-sm mb-4">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Severity</th>
                                <th>Sheet</th>
                                <th>Row</th>
                                <th>Issue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($result->issues as $issue)
                                <tr>
                                    <td>{{ $issue->blocking ? 'Error' : 'Warning' }}</td>
                                    <td>{{ $issue->sheet }}</td>
                                    <td>{{ $issue->rowNumber > 0 ? $issue->rowNumber : '—' }}</td>
                                    <td>{{ $issue->message }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($result->canApply && ! $result->alreadyApplied)
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route('inventory.opening-import.apply', $result->batch) }}">
                        @csrf
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="confirm" id="confirm" value="1" required>
                            <label class="form-check-label" for="confirm">
                                I confirm this is the physical-count workbook. Apply will add stock and will not rewrite existing inventory history.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">Apply opening inventory</button>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endsection
