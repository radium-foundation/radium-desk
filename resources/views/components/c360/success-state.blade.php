@props([
    'title',
    'items' => [],
])

<div {{ $attributes->merge(['class' => 'c360-dialog-success']) }}>
    <div class="c360-dialog-success-icon" aria-hidden="true">✓</div>
    <h3 class="c360-dialog-success-title">{{ $title }}</h3>
    @if(count($items) > 0)
        <ul class="c360-dialog-success-list mb-0">
            @foreach($items as $item)
                <li class="c360-dialog-success-item">
                    <span class="c360-dialog-success-check" aria-hidden="true">✓</span>
                    <span>{{ $item }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
