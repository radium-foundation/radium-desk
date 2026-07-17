@extends('layouts.app')

@section('title', $workforce->isSelf ? 'My Workforce' : $workforce->user->name)

@section('content')
    @include('workforce.partials.hero-member', [
        'hero' => $workforce->hero,
        'userName' => $workforce->user->name,
        'isSelf' => $workforce->isSelf,
        'teamUrl' => $workforce->teamUrl,
    ])

    <div class="workforce360-context-strip card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-3 small text-muted">
                <span><strong class="text-body">{{ $workforce->context['role_label'] ?? 'Team member' }}</strong></span>
                <span>{{ $workforce->context['assignment_pool'] ?? 'specialist_pool' }}</span>
            </div>
        </div>
    </div>

    @php
        $baseUrl = $workforce->isSelf
            ? route('my-workforce.index')
            : route('workforce.show', $workforce->user);
        $tabs = $workforce->tabs;
    @endphp

    @include('workforce.partials.tabs', [
        'tabs' => $tabs,
        'activeTab' => $activeTab,
        'baseUrl' => $baseUrl,
    ])

    @include('workforce.partials.member-tab-content', [
        'workforce' => $workforce,
        'activeTab' => $activeTab,
    ])
@endsection
