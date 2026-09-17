@extends('layouts.app')

@section('title', 'New service item')

@section('content')
    <div class="mb-4"><h1 class="h3">New service item</h1></div>
    @include('services.partials.workspace-nav')
    <form method="POST" action="{{ route('services.items.store') }}" class="card border-0 shadow-sm">
        <div class="card-body">
            @include('services.items._form', ['item' => null])
            <div class="mt-3"><button class="btn btn-primary">Create</button></div>
        </div>
    </form>
@endsection
