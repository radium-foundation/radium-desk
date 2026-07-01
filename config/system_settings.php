<?php

return [
    'categories' => [
        'system' => [
            'label' => 'System',
            'description' => 'Core platform behaviour and diagnostics.',
            'icon' => 'bi-cpu',
            'sort' => 10,
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'description' => 'WhatsApp API and automation feature flags.',
            'icon' => 'bi-whatsapp',
            'sort' => 20,
        ],
        'email' => [
            'label' => 'Email',
            'description' => 'Email channel integration flags.',
            'icon' => 'bi-envelope',
            'sort' => 30,
        ],
        'telegram' => [
            'label' => 'Telegram',
            'description' => 'Telegram channel integration flags.',
            'icon' => 'bi-telegram',
            'sort' => 40,
        ],
        'outbox' => [
            'label' => 'Outbox',
            'description' => 'Outbound message processing controls.',
            'icon' => 'bi-send',
            'sort' => 50,
        ],
        'ira' => [
            'label' => 'IRA',
            'description' => 'Intelligent response assistant controls.',
            'icon' => 'bi-stars',
            'sort' => 60,
        ],
    ],

    'settings' => [
        'system.debug_mode' => [
            'category' => 'system',
            'label' => 'Debug mode',
            'description' => 'Enable verbose diagnostics for troubleshooting.',
            'type' => 'boolean',
            'default' => false,
        ],
        'whatsapp.api_enabled' => [
            'category' => 'whatsapp',
            'label' => 'WhatsApp API',
            'description' => 'Allow outbound WhatsApp API calls.',
            'type' => 'boolean',
            'default' => true,
        ],
        'whatsapp.manual_templates_enabled' => [
            'category' => 'whatsapp',
            'label' => 'Manual templates',
            'description' => 'Allow agents to send manual WhatsApp templates.',
            'type' => 'boolean',
            'default' => true,
        ],
        'whatsapp.automation_enabled' => [
            'category' => 'whatsapp',
            'label' => 'WhatsApp automation',
            'description' => 'Enable automated WhatsApp workflows (future phases).',
            'type' => 'boolean',
            'default' => false,
        ],
        'email.api_enabled' => [
            'category' => 'email',
            'label' => 'Email API',
            'description' => 'Allow outbound email API calls.',
            'type' => 'boolean',
            'default' => true,
        ],
        'telegram.api_enabled' => [
            'category' => 'telegram',
            'label' => 'Telegram API',
            'description' => 'Allow outbound Telegram API calls.',
            'type' => 'boolean',
            'default' => false,
        ],
        'outbox.processor_enabled' => [
            'category' => 'outbox',
            'label' => 'Outbox processor',
            'description' => 'Process queued outbound messages.',
            'type' => 'boolean',
            'default' => true,
        ],
        'ira.enabled' => [
            'category' => 'ira',
            'label' => 'IRA enabled',
            'description' => 'Enable the intelligent response assistant.',
            'type' => 'boolean',
            'default' => true,
        ],
    ],
];
