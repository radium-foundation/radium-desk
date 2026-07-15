<?php

return [
  /*
  |--------------------------------------------------------------------------
  | Support Contact Configuration
  |--------------------------------------------------------------------------
  |
  | Single source of truth for customer-facing support contact details used in
  | notification emails. Values are environment-driven today and can later be
  | overridden at runtime by SupportContactConfiguration (admin settings).
  |
  */

    'email' => env('COMMUNICATION_ACTION_SUPPORT_EMAIL', env('COMMUNICATION_ACTION_SUPPORT_CONTACT', 'support@radiumbox.com')),

    'phone' => env('COMMUNICATION_ACTION_SUPPORT_PHONE', '+91 XXXXX XXXXX'),

    'whatsapp' => env('COMMUNICATION_ACTION_SUPPORT_WHATSAPP', ''),

    'website' => env('COMMUNICATION_ACTION_SUPPORT_WEBSITE', ''),

    // @deprecated Use email/phone instead. Kept for backward compatibility.
    'contact' => env('COMMUNICATION_ACTION_SUPPORT_CONTACT', 'support@radiumbox.com'),
];
