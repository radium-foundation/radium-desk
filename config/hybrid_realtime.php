<?php

use App\Services\HybridRealtime\HybridRealtimeFeature;

return [

    /*
    |--------------------------------------------------------------------------
    | Hybrid Realtime Features
    |--------------------------------------------------------------------------
    |
    | Central feature map for Hybrid Reverb. Runtime enablement is controlled by
    | System Settings (hybrid_realtime.*). Optional env keys act as hard
    | kill-switches: when explicitly false, the feature stays off regardless of
    | the admin toggle. When the env key is unset (null), only the system
    | setting applies.
    |
    */

    'features' => [
        HybridRealtimeFeature::REFERENCE_NUMBER => [
            'setting_key' => 'hybrid_realtime.reference_number',
            'env_kill_switch' => env('REVERB_REF_NO_ENABLED'),
            'wired' => true,
        ],
        HybridRealtimeFeature::ASSIGNMENT => [
            'setting_key' => 'hybrid_realtime.assignment',
            'env_kill_switch' => env('REVERB_ASSIGNMENT_ENABLED'),
            'wired' => true,
        ],
        HybridRealtimeFeature::CLOSE_RESOLVE => [
            'setting_key' => 'hybrid_realtime.close_resolve',
            'env_kill_switch' => env('REVERB_CASE_STATUS_ENABLED'),
            'wired' => true,
        ],
        HybridRealtimeFeature::INCOMING_CALLS => [
            'setting_key' => 'hybrid_realtime.incoming_calls',
            'env_kill_switch' => env('REVERB_INCOMING_CALL_ENABLED'),
            'wired' => true,
        ],
        HybridRealtimeFeature::DESKTOP_NOTIFICATIONS => [
            'setting_key' => 'hybrid_realtime.desktop_notifications',
            'env_kill_switch' => env('REVERB_DESKTOP_NOTIFICATION_ENABLED'),
            'wired' => true,
        ],
        HybridRealtimeFeature::OPERATOR_ALERTS => [
            'setting_key' => 'hybrid_realtime.operator_alerts',
            'env_kill_switch' => env('REVERB_OPERATOR_ALERT_ENABLED'),
            'wired' => true,
        ],
    ],

];
