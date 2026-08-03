@extends('layouts.app')

@section('title', 'Team Workforce')

@section('content')
    @include('workforce.partials.hero-team', ['hero' => $workforce->hero])

    @include('workforce.partials.pending-leave-approvals', [
        'pendingLeaveApprovals' => $pendingLeaveApprovals ?? ['visible' => false, 'items' => []],
    ])

    @include('workforce.partials.capacity-strip', ['capacity' => $workforce->capacity])

    @include('workforce.partials.hub-nav', ['active' => 'team'])

    @php
        $tabs = collect($workforce->tabs)
            ->filter(function (array $tab): bool {
                if (! in_array($tab['key'] ?? '', ['overview', 'timeline'], true)) {
                    return false;
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
