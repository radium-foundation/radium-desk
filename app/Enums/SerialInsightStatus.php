<?php

namespace App\Enums;

enum SerialInsightStatus: string
{
    case Missing = 'missing';
    case Valid = 'valid';
    case Warning = 'warning';
    case Suspicious = 'suspicious';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Missing',
            self::Valid => 'Valid',
            self::Warning => 'Needs verification',
            self::Suspicious => 'Suspicious',
            self::Pending => 'Pending',
        };
    }
}
