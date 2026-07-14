@extends('layouts.app')

@section('title', 'Create Refund Request')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('refunds.index') }}">Refunds</a></li>
                <li class="breadcrumb-item active" aria-current="page">Create</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Create Refund Request</h1>
        <p class="text-muted mb-0">
            Reference number will be assigned automatically (e.g. REF-{{ now()->format('Y') }}-000001).
        </p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('refunds.store') }}">
                @csrf

                @include('refunds.partials.form', [
                    'refund' => $refund,
                    'selectedOrder' => $selectedOrder,
                    'selectedIncident' => $selectedIncident,
                    'calculation' => $calculation ?? null,
                    'preferredMethods' => $preferredMethods ?? \App\Enums\CustomerPreferredRefundMethod::cases(),
                    'profiles' => $profiles ?? [],
                ])

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Submit Refund Request</button>
                    <a href="{{ route('refunds.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
