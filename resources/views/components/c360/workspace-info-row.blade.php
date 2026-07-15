@props([
    'label',
    'emphasis' => false,
])

<div @class(['workspace-info-dl-row', 'workspace-info-dl-row--emphasis' => $emphasis])>
    <dt>{{ $label }}</dt>
    <dd>{{ $slot }}</dd>
</div>
