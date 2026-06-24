@props(['source'])

<i class="{{ $source->icon() }} text-muted"
   role="img"
   aria-label="{{ $source->label() }}"
   data-bs-toggle="tooltip"
   data-bs-placement="top"
   data-bs-title="{{ $source->label() }}"></i>
