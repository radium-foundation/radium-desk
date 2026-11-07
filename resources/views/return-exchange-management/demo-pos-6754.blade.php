@extends('layouts.app')

@section('title', 'POS-6754 demo scenario')

@section('content')
    <div class="container py-4" style="max-width: 840px;">
        <a href="{{ route('return-exchange-management.index') }}" class="small">&larr; Management home</a>
        <h1 class="h4 mt-2">{{ $scenario['title'] }}</h1>
        <p class="alert alert-warning small">Read-only demo. No database records are created or changed.</p>

        <dl class="row small">
            <dt class="col-sm-4">POS sale</dt><dd class="col-sm-8">{{ $scenario['sale_no'] }} (id {{ $scenario['sale_id'] }})</dd>
            <dt class="col-sm-4">Invoice</dt><dd class="col-sm-8">{{ $scenario['invoice_number'] }}</dd>
            <dt class="col-sm-4">Finance journal</dt><dd class="col-sm-8">{{ $scenario['finance_journal_id'] }}</dd>
            <dt class="col-sm-4">Return serial</dt><dd class="col-sm-8">{{ $scenario['return_serial'] }} (USB)</dd>
            <dt class="col-sm-4">Replacement serial</dt><dd class="col-sm-8">{{ $scenario['replacement_serial'] }} (Type-C intent)</dd>
            <dt class="col-sm-4">Category</dt><dd class="col-sm-8">{{ $scenario['category'] }}</dd>
            <dt class="col-sm-4">Price difference</dt><dd class="col-sm-8">{{ $scenario['price_difference'] }}</dd>
            <dt class="col-sm-4">Statutory treatment</dt><dd class="col-sm-8">{{ $scenario['statutory_treatment'] }}</dd>
            <dt class="col-sm-4">Finance reconciliation</dt><dd class="col-sm-8">{{ $scenario['finance_reconciliation'] }}</dd>
        </dl>

        <h2 class="h6">Unaffected serials (must remain sold on POS-6754)</h2>
        <ul>
            @foreach ($scenario['unaffected_serials'] as $serial)
                <li><code>{{ $serial }}</code></li>
            @endforeach
        </ul>

        <h2 class="h6">Expected UI blockers</h2>
        <ul class="small">
            @foreach ($scenario['checklist_blocked'] as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </div>
@endsection
