@extends('layouts.app')

@section('title', 'Service categories')

@section('content')
    <div class="mb-4"><h1 class="h3">Service categories</h1></div>
    @include('services.partials.workspace-nav', ['active' => 'categories'])
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Code</th><th>Name</th><th>Items</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($categories as $category)
                        <tr>
                            <td>{{ $category->code }}</td>
                            <td>{{ $category->name }}</td>
                            <td>{{ $category->items_count }}</td>
                            <td>{{ $category->is_active ? 'Active' : 'Inactive' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
