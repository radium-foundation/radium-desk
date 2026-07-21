<?php

namespace App\Services\HybridRealtime;

final class HybridRealtimeFeature
{
    public const REFERENCE_NUMBER = 'reference_number';

    public const ASSIGNMENT = 'assignment';

    public const CLOSE_RESOLVE = 'close_resolve';

    public const INCOMING_CALLS = 'incoming_calls';

    public const DESKTOP_NOTIFICATIONS = 'desktop_notifications';

    public const OPERATOR_ALERTS = 'operator_alerts';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::REFERENCE_NUMBER,
            self::ASSIGNMENT,
            self::CLOSE_RESOLVE,
            self::INCOMING_CALLS,
            self::DESKTOP_NOTIFICATIONS,
            self::OPERATOR_ALERTS,
        ];
    }
}
