<?php

namespace App\Enums;

enum SerialValidationSeverity: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Fail = 'fail';

    public function isPass(): bool
    {
        return $this === self::Pass;
    }

    public function isWarning(): bool
    {
        return $this === self::Warning;
    }

    public function isFail(): bool
    {
        return $this === self::Fail;
    }

    public function allowsWorkflow(): bool
    {
        return $this === self::Pass || $this === self::Warning;
    }
}
