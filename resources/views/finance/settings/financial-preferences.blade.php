@extends('layouts.app')

@section('title', 'Financial Preferences')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <h1 class="h3 mb-1">Financial Preferences</h1>
        <p class="text-muted mb-0">Finance preferences will be configured here.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'financial_preferences'])
    @include('finance.partials.placeholder')
@endsection
