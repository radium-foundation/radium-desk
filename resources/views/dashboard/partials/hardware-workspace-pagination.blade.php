@php
    use App\Enums\HardwareWorkspaceScope;

    $rows = $hardwareWorkspace['rows'] ?? collect();
    $search = $hardwareWorkspace['search'] ?? '';
    $page = max(1, (int) ($hardwareWorkspace['page'] ?? 1));
    $perPage = max(1, (int) ($hardwareWorkspace['per_page'] ?? 40));
    $matching = max(0, (int) ($hardwareWorkspace['unfilteredTotal'] ?? $rows->count()));
    $lastPage = max(1, (int) ceil($matching / $perPage));
    $pageQuery = static function (int $pageNum) use ($hardwareWorkspace, $search): string {
        return route('dashboard', array_filter([
            'workspace' => 'hardware',
            'hw_scope' => $hardwareWorkspace['scope'] ?? 'active',
            'hw_filter' => $hardwareWorkspace['filter'] ?? 'needs_action',
            'q' => $search !== '' ? $search : null,
            'hw_page' => $pageNum > 1 ? $pageNum : null,
        ], static fn ($value) => $value !== null && $value !== ''));
    };
@endphp
@if($matching > $perPage)
    <nav class="dashboard-hardware-pagination" aria-label="Hardware queue pages" data-hardware-pagination>
        @if($page > 1)
            <a href="{{ $pageQuery($page - 1) }}">Previous</a>
        @endif
        <span>Page {{ $page }} of {{ $lastPage }} ({{ $matching }})</span>
        @if($page < $lastPage)
            <a href="{{ $pageQuery($page + 1) }}">Next</a>
        @endif
    </nav>
@endif
