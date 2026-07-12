<?php

use Database\Seeders\RolePermissionSeeder;

return [
    'urls' => [
        'review' => env('COMMUNICATION_ACTION_REVIEW_URL', 'https://g.page/r/radiumbox/review'),
        'buy_rd_service' => env('COMMUNICATION_ACTION_BUY_RD_SERVICE_URL', 'https://radiumbox.com/rd-service'),
        'buy_product' => env('COMMUNICATION_ACTION_BUY_PRODUCT_URL', 'https://radiumbox.com/shop'),
    ],

    'actions' => [
        'driver_installation_guide' => [
            'key' => 'driver_installation_guide',
            'name' => 'Driver Installation Guide',
            'description' => 'Send driver setup instructions to help the customer install their biometric device.',
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
            'variables' => [
                'reference_number' => [
                    'type' => 'text',
                    'label' => 'Reference Number',
                    'required' => false,
                ],
            ],
            'automation' => [
                'enabled' => false,
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
            'variables' => [],
            'automation' => [
                'enabled' => false,
                'future_trigger' => null,
            ],
        ],

        'refund_confirmation' => [
            'key' => 'refund_confirmation',
            'name' => 'Refund Confirmation',
            'description' => 'Confirm refund details with the customer after the refund workflow is complete.',
            'icon' => 'bi-arrow-counterclockwise',
            'channels' => ['whatsapp', 'email'],
            'roles' => [
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            'notification_type' => 'refund_confirmation',
            'whatsapp_template' => 'refund_update',
            'timeline_label' => 'Refund confirmation sent',
            'execution_mode' => 'manual',
            'variables' => [
                'refund_amount' => [
                    'type' => 'text',
                    'label' => 'Refund Amount',
                    'required' => false,
                ],
                'refund_reference' => [
                    'type' => 'text',
                    'label' => 'Refund Reference',
                    'required' => false,
                ],
            ],
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
            'channels' => ['whatsapp'],
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
            'channels' => ['whatsapp'],
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
            'variables' => [
                'product_name' => [
                    'type' => 'text',
                    'label' => 'Product Name',
                    'required' => false,
                ],
            ],
            'automation' => [
                'enabled' => false,
                'future_trigger' => null,
            ],
        ],
    ],
];
