<?php

namespace App\Enums;

enum BonvoiceClickToCallFailureCode: string
{
    case Disabled = 'disabled';

    case NotConfigured = 'not_configured';

    case AgentPhone = 'agent_phone';

    case CustomerPhone = 'customer_phone';

    case Auth = 'auth';

    case Connection = 'connection';

    case ProviderHttp = 'provider_http';

    case ProviderResponse = 'provider_response';

    case InvalidResponse = 'invalid_response';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Click-to-Call disabled',
            self::NotConfigured => 'Click-to-Call not configured',
            self::AgentPhone => 'Agent mobile missing or invalid',
            self::CustomerPhone => 'Customer phone missing or invalid',
            self::Auth => 'BonVoice authentication failed',
            self::Connection => 'Unable to reach BonVoice',
            self::ProviderHttp => 'BonVoice HTTP error',
            self::ProviderResponse => 'BonVoice rejected call',
            self::InvalidResponse => 'BonVoice returned invalid response',
        };
    }

    /**
     * Safe, user-facing copy. Never includes provider response bodies.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::AgentPhone => 'Your call mobile number is not configured. Ask an admin to set it on your user profile.',
            self::CustomerPhone => 'No customer phone number is available for calling.',
            self::Disabled,
            self::NotConfigured,
            self::Auth,
            self::Connection,
            self::ProviderHttp,
            self::ProviderResponse,
            self::InvalidResponse => 'Automatic calling failed.',
        };
    }
}
