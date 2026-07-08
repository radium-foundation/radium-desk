@extends('layouts.app')

@section('title', 'Changelog')

@section('content')
    <div class="container-fluid py-3">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0">What's New</h1>
                <p class="text-muted small mb-0">{{ config('app.name', 'Radium Desk') }} v{{ config('app.version', '0.0.0') }}</p>
            </div>
            <div class="card-body">
                @forelse($entries as $entry)
                    <section @class(['mb-4' => ! $loop->last])>
                        <h2 class="h6 fw-semibold mb-2">{{ $entry['title'] }}</h2>
                        @if($entry['items'] !== [])
                            <ul class="mb-0">
                                @foreach($entry['items'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @empty
                    <p class="text-muted mb-0">Release notes are not available yet.</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection
