@extends('layouts.app')

@section('title', 'New Template')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">New Template</h1>
        <p class="text-muted mb-0">Creates version 1 as Draft unless imported as Approved.</p>
    </div>
    @include('navigation.administration-workspace-nav', ['active' => 'communication'])

    <form method="POST" action="{{ route('admin.communication-templates.store') }}" data-template-editor>
        @csrf
        @include('admin.communication-templates.partials.form')
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Create Template</button>
            <a href="{{ route('admin.communication-templates.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
@endsection

@push('scripts')
    @vite('resources/js/communication-template-editor.js')
@endpush
