<?php

namespace App\Enums;

enum WorkSessionOrigin: string
{
    case Login = 'login';
    case Browser = 'browser';
    case System = 'system';
    case Assignment = 'assignment';
    case Migration = 'migration';

    public function isAttributableByDefault(): bool
    {
        return match ($this) {
            self::Login, self::Browser => true,
            self::System, self::Assignment, self::Migration => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Login',
            self::Browser => 'Browser',
            self::System => 'System',
            self::Assignment => 'Assignment',
            self::Migration => 'Migration',
        };
    }
}
