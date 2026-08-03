@extends('layouts.app')

@section('title', 'Compare · '.$template->name)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Template Comparison</h1>
            <p class="text-muted mb-0">{{ $template->name }} · Blade vs Approved Store</p>
        </div>
        <a href="{{ route('admin.communication-templates.show', $template) }}" class="btn btn-outline-secondary">Back</a>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'communication'])

    <div class="alert {{ $comparison['identical'] ? 'alert-success' : 'alert-warning' }}">
        {{ $comparison['identical'] ? 'Outputs match (normalized text).' : 'Differences detected ('.round($comparison['diff_ratio'] * 100).'% distance). Review before relying on store runtime.' }}
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Blade output</h2>
                    <div class="small text-muted">{{ $comparison['blade_subject'] }}</div>
                </div>
                <div class="card-body">{!! $comparison['blade_html'] !!}</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Store output</h2>
                    <div class="small text-muted">{{ $comparison['store_subject'] }}</div>
                </div>
                <div class="card-body">{!! $comparison['store_html'] !!}</div>
            </div>
        </div>
    </div>
@endsection
