<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Support Assignment Engine
    |--------------------------------------------------------------------------
    |
    | Phase 3 architecture flags. Defaults preserve current production behaviour.
    |
    */

    'strategy' => env('SUPPORT_ASSIGNMENT_STRATEGY', 'round_robin'),

    /*
    | When false (default), SupportQueueAssignmentStrategy keeps the legacy path.
    | When true, support intake routes through SupportAssignmentEngine.
    */
    'use_engine' => env('SUPPORT_ASSIGNMENT_USE_ENGINE', false),

    'strategies' => [
        'round_robin' => \App\Support\Assignment\Strategies\Support\RoundRobinSupportAssignmentStrategy::class,
        'least_workload' => \App\Support\Assignment\Strategies\Support\LeastWorkloadSupportAssignmentStrategy::class,
        'performance' => \App\Support\Assignment\Strategies\Support\PerformanceBasedSupportAssignmentStrategy::class,
        'skill_based' => \App\Support\Assignment\Strategies\Support\SkillBasedSupportAssignmentStrategy::class,
        'hybrid' => \App\Support\Assignment\Strategies\Support\HybridSupportAssignmentStrategy::class,
    ],

];
