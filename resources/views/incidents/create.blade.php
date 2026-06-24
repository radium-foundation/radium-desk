@extends('layouts.app')

@section('title', 'Log Incident')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('incidents.index') }}">Incidents</a></li>
                <li class="breadcrumb-item active" aria-current="page">Create</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Log Incident</h1>
        <p class="text-muted mb-0">Reference number will be assigned automatically after submission.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('incidents.store') }}">
                @csrf

                @include('incidents.partials.form', [
                    'incident' => $incident,
                    'selectedOrder' => $selectedOrder,
                    'showStatus' => false,
                    'categories' => \App\Models\Incident::query()->select('category')->distinct()->orderBy('category')->pluck('category'),
                ])

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Create Incident</button>
                    <a href="{{ route('incidents.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
