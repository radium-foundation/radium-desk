@props([
    'intelligence' => [],
])

<div class="operations-today-tab-content">
    <div id="operations-support-intelligence" class="mb-0">
        @include('admin.operations.partials.support-intelligence', [
            'intelligence' => $intelligence,
        ])
    </div>
</div>
