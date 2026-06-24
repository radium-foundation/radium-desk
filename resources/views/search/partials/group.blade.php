@if($paginator->total() > 0)
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
                <h2 class="h6 mb-0">{{ $title }}</h2>
                <span class="badge text-bg-light text-dark border">{{ number_format($paginator->total()) }}</span>
            </div>
            <div class="card-body py-3">
                <ul class="list-unstyled mb-0 search-result-list">
                    @foreach($paginator as $result)
                        @include('search.partials.result-item', ['result' => $result, 'group' => $title])
                    @endforeach
                </ul>
            </div>
            @if($paginator->hasPages())
                <div class="card-footer bg-white border-top-0 pt-0">
                    {{ $paginator->links() }}
                </div>
            @endif
        </div>
    </div>
@endif
