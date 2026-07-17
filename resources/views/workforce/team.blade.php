@extends('layouts.app')

@section('title', 'Team Workforce')

@section('content')
    @include('workforce.partials.hero-team', ['hero' => $workforce->hero])

    @include('workforce.partials.capacity-strip', ['capacity' => $workforce->capacity])

    @php
        $tabs = collect($workforce->tabs)
            ->filter(function (array $tab): bool {
                if (($tab['key'] ?? '') === 'holidays') {
                    return auth()->user()?->can('viewAny', App\Models\CompanyHoliday::class) ?? false;
                }

                return true;
            })
            ->values()
            ->all();
    @endphp

    @include('workforce.partials.tabs', [
        'tabs' => $tabs,
        'activeTab' => $activeTab,
        'baseUrl' => route('workforce.index'),
    ])

    @if($activeTab === 'overview')
        @include('workforce.partials.member-list', ['members' => $workforce->members])
    @elseif($activeTab === 'timeline')
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted">
                Workforce timeline will be projected through the Timeline Engine in a future sprint.
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted">
                Use the linked tab to manage this workforce area.
            </div>
        </div>
    @endif
@endsection
