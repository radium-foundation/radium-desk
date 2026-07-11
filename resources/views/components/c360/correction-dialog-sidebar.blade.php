@props([
    'order' => null,
    'incident' => null,
    'workspaceContext' => null,
    'showSerialAction' => true,
    'canCorrectSerialNumber' => false,
])

<x-c360.identity-summary
    :order="$order"
    :incident="$incident"
    :workspace-context="$workspaceContext"
    :show-serial-action="$showSerialAction"
    :can-correct-serial-number="$canCorrectSerialNumber"
    variant="sidebar" />
