<?php

use App\Enums\WorkspaceContext;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Workspace Context
    |--------------------------------------------------------------------------
    |
    | Used when a workspace request does not specify a context explicitly.
    | Service case pages omit an explicit context; the dashboard declares
    | data-workspace-context="dashboard" on its page root.
    |
    */

    'default' => WorkspaceContext::ServiceCase->value,

    /*
    |--------------------------------------------------------------------------
    | Request Keys
    |--------------------------------------------------------------------------
    */

    'keys' => [
        'query' => 'context',
        'body' => 'workspace_context',
        'header' => 'X-Workspace-Context',
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Slugs
    |--------------------------------------------------------------------------
    |
    | Canonical slug map exposed to JavaScript via the layout bootstrap.
    |
    */

    'contexts' => [
        'Dashboard' => WorkspaceContext::Dashboard->value,
        'ServiceCase' => WorkspaceContext::ServiceCase->value,
        'Order' => WorkspaceContext::Order->value,
        'Customer' => WorkspaceContext::Customer->value,
        'Mobile' => WorkspaceContext::Mobile->value,
        'Api' => WorkspaceContext::Api->value,
        'Ai' => WorkspaceContext::Ai->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Refresh Target Selectors
    |--------------------------------------------------------------------------
    |
    | DOM anchors referenced by WorkspaceRefreshPolicy. HTML is rendered later
    | by action services; the policy only declares which targets to patch.
    |
    */

    'targets' => [
        'activity_timeline' => '#activity-timeline',
        'service_case_header' => '.service-case-header',
        'order_show' => '[data-order-show]',
        'workspace_modal_content' => '[data-workspace-modal-content]',
    ],

];
