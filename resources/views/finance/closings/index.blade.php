@extends('layouts.app')

@section('title', 'Daily Closing')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Daily Closing</h1>
        <p class="text-muted mb-0">Daily cash closing and lock will live here.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'closings'])

    @include('finance.partials.placeholder')
@endsection
