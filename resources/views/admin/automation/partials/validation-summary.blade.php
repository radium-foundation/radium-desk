@props([
    'byProduct' => [],
    'byValidatorRule' => [],
    'byCategory' => [],
])

<section class="mb-4" aria-labelledby="validation-summary-heading">
    <h2 id="validation-summary-heading" class="h5 mb-3">Validation Summary</h2>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h3 class="h6 mb-0">By Product</h3>
                </div>
                <div class="card-body p-0">
                    @if($byProduct === [])
                        <div class="p-4 text-center text-muted">No validation failures by product.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($byProduct as $product => $count)
                                        <tr>
                                            <td>{{ $product }}</td>
                                            <td class="text-end fw-semibold">{{ number_format($count) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h3 class="h6 mb-0">By Validator Rule</h3>
                </div>
                <div class="card-body p-0">
                    @if($byValidatorRule === [])
                        <div class="p-4 text-center text-muted">No validator rule failures.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Rule</th>
                                        <th class="text-end">Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($byValidatorRule as $rule => $count)
                                        <tr>
                                            <td class="font-monospace small">{{ $rule }}</td>
                                            <td class="text-end fw-semibold">{{ number_format($count) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h3 class="h6 mb-0">By Category</h3>
                </div>
                <div class="card-body p-0">
                    @if($byCategory === [])
                        <div class="p-4 text-center text-muted">No validation failures by category.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-end">Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($byCategory as $category => $count)
                                        <tr>
                                            <td>{{ $category }}</td>
                                            <td class="text-end fw-semibold">{{ number_format($count) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
