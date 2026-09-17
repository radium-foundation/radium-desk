@extends('layouts.app')

@section('title', 'Edit service item')

@section('content')
    <div class="mb-4"><h1 class="h3">Edit {{ $item->name }}</h1></div>
    @include('services.partials.workspace-nav')
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('services.items.update', $item) }}" class="card border-0 shadow-sm">
        <div class="card-body">
            @method('PUT')
            @include('services.items._form')
            <div class="mt-3"><button class="btn btn-primary">Save</button></div>
        </div>
    </form>
@endsection
