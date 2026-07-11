@props([
    'title' => 'Impact',
    'items' => [],
])

<section {{ $attributes->merge(['class' => 'c360-dialog-impact-checklist']) }}
         aria-label="{{ $title }}">
    <h4 class="c360-dialog-impact-checklist-title">{{ $title }}</h4>
    <ul class="c360-dialog-impact-checklist-list mb-0">
        @foreach($items as $item)
            <li class="c360-dialog-impact-checklist-item">
                <span class="c360-dialog-impact-checklist-check" aria-hidden="true">✓</span>
                <span>{{ $item }}</span>
            </li>
        @endforeach
    </ul>
</section>
