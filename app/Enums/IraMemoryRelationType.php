<?php

namespace App\Enums;

enum IraMemoryRelationType: string
{
    case Related = 'related';
    case DuplicateOf = 'duplicate_of';
    case Supersedes = 'supersedes';
    case ConflictsWith = 'conflicts_with';

    public function label(): string
    {
        return match ($this) {
            self::Related => 'Related',
            self::DuplicateOf => 'Duplicate Of',
            self::Supersedes => 'Supersedes',
            self::ConflictsWith => 'Conflicts With',
        };
    }
}
