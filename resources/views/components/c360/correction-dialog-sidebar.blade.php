@props([
    'order' => null,
    'incident' => null,
    'workspaceContext' => null,
    'showSerialAction' => true,
    'canCorrectSerialNumber' => false,
    'showDeviceModelAction' => true,
    'canCorrectDeviceModel' => false,
])

<x-c360.identity-summary
    :order="$order"
    :incident="$incident"
    :workspace-context="$workspaceContext"
    :show-serial-action="$showSerialAction"
    :can-correct-serial-number="$canCorrectSerialNumber"
    :show-device-model-action="false"
    :can-correct-device-model="$canCorrectDeviceModel"
    variant="sidebar" />
