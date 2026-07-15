@props([
    'label',
    'emphasis' => false,
])

<div @class(['workspace-kpi-item', 'workspace-kpi-item--emphasis' => $emphasis])>
    <dt class="workspace-kpi-item__label">{{ $label }}</dt>
    <dd class="workspace-kpi-item__value">{{ $slot }}</dd>
</div>
