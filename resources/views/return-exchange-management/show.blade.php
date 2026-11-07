@extends('layouts.app')

@section('title', $case->case_no.' — management')

@section('content')
    <div class="container py-3" style="max-width: 960px;">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('return-exchange-management.index') }}">Return &amp; exchange</a></li>
                <li class="breadcrumb-item active">{{ $case->case_no }}</li>
            </ol>
        </nav>

        @if (session('status'))
            <div class="alert alert-success small">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger small">
                <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1">{{ $case->case_no }}</h1>
                <p class="text-muted small mb-0">{{ $case->replacement_category?->label() ?? 'Category not set' }}</p>
            </div>
            <a href="{{ $legacyShowUrl }}" class="btn btn-outline-secondary btn-sm">Receive / inspect / legacy actions</a>
        </div>

        @include('return-exchange-management.partials.documents-finance', ['case' => $case])
        @include('return-exchange-management.partials.readiness', [
            'checklist' => $checklist,
            'blockingReasons' => $blockingReasons,
            'mayComplete' => $mayComplete,
        ])
        @include('return-exchange-management.partials.approvals', [
            'case' => $case,
            'approvalTypes' => $approvalTypes,
            'approvalPermissions' => $approvalPermissions,
        ])
        @include('return-exchange-management.partials.planned-replacement', [
            'case' => $case,
            'canPlanReplacement' => $canPlanReplacement,
        ])
        @include('return-exchange-management.partials.completion', [
            'case' => $case,
            'mayComplete' => $mayComplete,
            'blockingReasons' => $blockingReasons,
        ])
    </div>
@endsection
