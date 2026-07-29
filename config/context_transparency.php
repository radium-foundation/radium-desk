<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Context Transparency (BR-03)
    |--------------------------------------------------------------------------
    |
    | When enabled, presenters and the Customer360 card catalog may expose
    | ContextBadge metadata (scope / label / icon / color token).
    |
    | Phase 1 foundation only: default OFF. No UI rendering, no query changes,
    | no API contract changes. Enable to unlock metadata for tests and future
    | scoped rendering (BR-02 layout work).
    |
    */

    'enabled' => (bool) env('CONTEXT_TRANSPARENCY_ENABLED', false),

];
