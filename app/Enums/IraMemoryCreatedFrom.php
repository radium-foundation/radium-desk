<?php

namespace App\Enums;

enum IraMemoryCreatedFrom: string
{
    case LearningCenter = 'learning_center';
    case Disposition = 'disposition';
    case SystemSeed = 'system_seed';
    case Import = 'import';
    case Migration = 'migration';
    case ManualEdit = 'manual_edit';

    public function label(): string
    {
        return match ($this) {
            self::LearningCenter => 'Learning Center',
            self::Disposition => 'Disposition',
            self::SystemSeed => 'System Seed',
            self::Import => 'Import',
            self::Migration => 'Migration',
            self::ManualEdit => 'Manual Edit',
        };
    }
}
