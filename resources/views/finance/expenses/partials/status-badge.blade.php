@props(['status'])

@php
    /** @var \App\Enums\FinanceExpenseStatus $status */
@endphp

<span class="badge text-bg-{{ $status->badgeClass() }}">{{ $status->label() }}</span>
