<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assignment Capability Settings
    |--------------------------------------------------------------------------
    |
    | Maps capability keys to settings keys. User IDs are never hardcoded;
    | capabilities resolve through the settings store at runtime.
    |
    */

    'role_mappings' => [
        \App\Enums\Assignment\AssignmentCapability::SupportAgent->value => \Database\Seeders\RolePermissionSeeder::SUPPORT_TEAM_ROLES,
        \App\Enums\Assignment\AssignmentCapability::ReadyQueueAdmin->value => [
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ],
        \App\Enums\Assignment\AssignmentCapability::AfterHoursSupport->value => [
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ],
        \App\Enums\Assignment\AssignmentCapability::IncomingEmailSupervisor->value => [
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ],
        \App\Enums\Assignment\AssignmentCapability::WhatsAppSupervisor->value => [
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ],
        \App\Enums\Assignment\AssignmentCapability::SalesLeadHandler->value => [
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ],
    ],

    'capabilities' => [
        \App\Enums\Assignment\AssignmentCapability::ReadyQueueAdmin->value => [
            'resolver' => 'shift_aware_setting',
            'day_setting_key' => 'assignment.ready_queue_day_admin_user_id',
            'night_setting_key' => 'assignment.ready_queue_night_admin_user_id',
            'fallback_resolver' => 'shift_admin',
        ],
        \App\Enums\Assignment\AssignmentCapability::AfterHoursSupport->value => [
            'resolver' => 'setting_with_fallback',
            'setting_key' => 'assignment.after_hours_support_user_id',
            'fallback_resolver' => 'shift_admin',
        ],
        \App\Enums\Assignment\AssignmentCapability::IncomingEmailSupervisor->value => [
            'resolver' => 'setting_with_fallback',
            'setting_key' => 'assignment.incoming_email_supervisor_user_id',
            'fallback_capability' => \App\Enums\Assignment\AssignmentCapability::AfterHoursSupport->value,
        ],
        \App\Enums\Assignment\AssignmentCapability::WhatsAppSupervisor->value => [
            'resolver' => 'setting_with_fallback',
            'setting_key' => 'assignment.whatsapp_supervisor_user_id',
            'fallback_capability' => \App\Enums\Assignment\AssignmentCapability::AfterHoursSupport->value,
        ],
        \App\Enums\Assignment\AssignmentCapability::SalesLeadHandler->value => [
            'resolver' => 'setting_with_fallback',
            'setting_key' => 'assignment.sales_lead_handler_user_id',
            'fallback_capability' => \App\Enums\Assignment\AssignmentCapability::ReadyQueueAdmin->value,
        ],
        \App\Enums\Assignment\AssignmentCapability::SupportAgent->value => [
            'resolver' => 'support_pool',
        ],
    ],

];
