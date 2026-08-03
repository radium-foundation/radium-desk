@extends('layouts.app')

@section('title', 'Edit Template')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Edit {{ $template->name }}</h1>
        <p class="text-muted mb-0">Saving always creates a new version. History is never overwritten.</p>
    </div>
    @include('navigation.administration-workspace-nav', ['active' => 'communication'])

    <form method="POST" action="{{ route('admin.communication-templates.update', $template) }}"
          data-template-editor
          data-preview-url="{{ route('admin.communication-templates.preview', $template) }}">
        @csrf
        @method('PUT')
        @include('admin.communication-templates.partials.form')
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save New Version</button>
            <a href="{{ route('admin.communication-templates.show', $template) }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
@endsection

@push('scripts')
    @vite('resources/js/communication-template-editor.js')
@endpush
