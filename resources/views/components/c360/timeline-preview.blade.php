@props([
    'title' => 'This action will',
    'items' => [],
])

<section {{ $attributes->merge(['class' => 'c360-dialog-timeline-preview']) }}
         aria-label="{{ $title }}">
    <h4 class="c360-dialog-timeline-preview-title">{{ $title }}</h4>
    <ul class="c360-dialog-timeline-preview-list mb-0">
        @foreach($items as $item)
            <li class="c360-dialog-timeline-preview-item">
                <span class="c360-dialog-timeline-preview-check" aria-hidden="true">✓</span>
                <span>{{ $item }}</span>
            </li>
        @endforeach
    </ul>
</section>
