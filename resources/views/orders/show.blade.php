@extends('layouts.app')

@section('title', $order->order_id)

@section('content')
    <div class="order-workspace" data-order-workspace>
        <div class="order-workspace-layout">
            @include('orders.workspace.partials.left-panel', [
                'order' => $order,
                'activeIncident' => $activeIncident ?? null,
                'activityTimeline' => $activityTimeline,
            ])

            @include('orders.workspace.partials.center-panel', [
                'order' => $order,
                'activeIncident' => $activeIncident ?? null,
                'activityTimeline' => $activityTimeline,
                'timelineRemarks' => $timelineRemarks,
            ])

            @include('orders.workspace.partials.agent-assistant', ['order' => $order])
        </div>
    </div>
@endsection
