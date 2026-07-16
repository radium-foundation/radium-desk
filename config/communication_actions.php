<?php

use Database\Seeders\RolePermissionSeeder;

$supportContact = require __DIR__.'/support_contact.php';

return [
    'urls' => [
        'review' => env('COMMUNICATION_ACTION_REVIEW_URL', 'https://g.page/r/radiumbox/review'),
    ],

    'review_platforms' => [
        [
            'key' => 'google',
            'name' => 'Google Review',
            'url' => env('COMMUNICATION_ACTION_REVIEW_URL', 'https://g.page/r/radiumbox/review'),
        ],
        [
            'key' => 'trustpilot',
            'name' => 'Trustpilot',
            'url' => env('COMMUNICATION_ACTION_TRUSTPILOT_REVIEW_URL'),
        ],
    ],

    'support_email' => $supportContact['email'],

    'support_phone' => $supportContact['phone'],

    'support_whatsapp' => $supportContact['whatsapp'],

    'support_website' => $supportContact['website'],

    // @deprecated Use support_email/support_phone instead. Kept for backward compatibility.
    'support_contact' => $supportContact['contact'],

    'company_name' => env('COMMUNICATION_ACTION_COMPANY_NAME', 'Radium Box'),

    'driver_installation_guide' => [
        'restart_instructions' => env(
            'COMMUNICATION_ACTION_DRIVER_RESTART_INSTRUCTIONS',
            'Restart your computer after installing the driver, then reconnect the biometric device.',
        ),
    ],

    'actions' => [
        'driver_installation_guide' => [
            'key' => 'driver_installation_guide',
            'name' => 'Driver Installation Guide',
            'description' => 'Send driver setup instructions, download link, and basic restart guidance to help the customer install their biometric device.',
            'icon' => 'bi-download',
            'channels' => ['whatsapp', 'email'],
            'roles' => [
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            'notification_type' => 'driver_installation_guide',
            'whatsapp_template' => 'driver_installation_guide',
            'timeline_label' => 'Driver installation guide sent',
            'execution_mode' => 'manual',
            'allowed_on_closed_incident' => true,
            'variables' => [],
            'automation' => [
                'enabled' => true,
                'future_trigger' => 'reference_number_added',
            ],
        ],

        'review_request' => [
            'key' => 'review_request',
            'name' => 'Review Request',
            'description' => 'Ask the customer to leave a review after a successful remote support session.',
            'icon' => 'bi-star',
            'channels' => ['whatsapp', 'email'],
            'roles' => [
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            'notification_type' => 'review_request',
            'whatsapp_template' => 'review_request',
            'timeline_label' => 'Review request sent',
            'execution_mode' => 'manual',
            'allowed_on_closed_incident' => true,
            'variables' => [],
            'automation' => [
                'enabled' => false,
                'future_trigger' => null,
            ],
        ],

        'refund_confirmation' => [
            'key' => 'refund_confirmation',
            'name' => 'Refund Confirmation',
            'description' => 'Confirm refund details with the customer after the refund has been completed.',
            'icon' => 'bi-arrow-counterclockwise',
            'channels' => ['whatsapp', 'email'],
            'roles' => [
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            'notification_type' => 'refund_confirmation',
            'whatsapp_template' => 'refund_confirmation',
            'timeline_label' => 'Refund confirmation sent',
            'execution_mode' => 'manual',
            'allowed_on_closed_incident' => true,
            'variables' => [],
            'automation' => [
                'enabled' => false,
                'future_trigger' => null,
            ],
        ],

        'buy_rd_service' => [
            'key' => 'buy_rd_service',
            'name' => 'Buy RD Service',
            'description' => 'Share RD Service purchase information with the customer.',
            'icon' => 'bi-bag-check',
            'channels' => ['whatsapp', 'email'],
            'roles' => [
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            'notification_type' => 'buy_rd_service',
            'whatsapp_template' => 'buy_rd_service',
            'timeline_label' => 'RD Service purchase link sent',
            'execution_mode' => 'manual',
            'allowed_on_closed_incident' => true,
            'variables' => [],
            'automation' => [
                'enabled' => false,
                'future_trigger' => null,
            ],
        ],

        'buy_product' => [
            'key' => 'buy_product',
            'name' => 'Buy Product',
            'description' => 'Share product purchase information with the customer.',
            'icon' => 'bi-cart',
            'channels' => ['whatsapp', 'email'],
            'roles' => [
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            'notification_type' => 'buy_product',
            'whatsapp_template' => 'buy_product',
            'timeline_label' => 'Product purchase link sent',
            'execution_mode' => 'manual',
            'allowed_on_closed_incident' => true,
            'variables' => [],
            'automation' => [
                'enabled' => false,
                'future_trigger' => null,
            ],
        ],
    ],
];
