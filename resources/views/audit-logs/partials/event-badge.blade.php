@props(['auditLog'])

<span class="badge text-bg-light text-dark border text-capitalize">
    {{ audit_event_label($auditLog->event) }}
</span>
