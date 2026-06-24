@props(['auditLog'])

<span class="badge text-bg-light text-dark border text-capitalize">
    {{ str_replace('_', ' ', $auditLog->event) }}
</span>
