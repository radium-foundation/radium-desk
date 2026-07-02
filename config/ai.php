<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Determines which AI provider implementation is used. Supported values:
    | null, openai, anthropic, gemini, local (future providers).
    |
    */

    'provider' => env('AI_PROVIDER', 'null'),

];
