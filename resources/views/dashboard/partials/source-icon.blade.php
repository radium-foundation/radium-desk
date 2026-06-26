@props(['source'])

<span class="dashboard-source-icon">
    <i class="{{ $source->icon() }}"
       role="img"
       aria-label="{{ $source->label() }}"
       data-bs-toggle="tooltip"
       data-bs-placement="top"
       data-bs-title="{{ $source->label() }}"></i>
</span>
