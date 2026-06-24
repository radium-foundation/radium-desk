@extends('layouts.app')

@section('title', 'Edit '.config('ui.service_case.singular'))

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('incidents.index') }}">{{ config('ui.service_case.plural') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('incidents.show', $incident) }}">{{ $incident->reference_no }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Edit {{ config('ui.service_case.singular') }}</h1>
        <p class="text-muted mb-0">{{ $incident->reference_no }}</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('incidents.update', $incident) }}">
                @csrf
                @method('PUT')

                @include('incidents.partials.form', [
                    'incident' => $incident,
                    'categories' => \App\Models\Incident::query()->select('category')->distinct()->orderBy('category')->pluck('category'),
                ])

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('incidents.show', $incident) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
