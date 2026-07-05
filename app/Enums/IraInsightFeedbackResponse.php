<?php

namespace App\Enums;

enum IraInsightFeedbackResponse: string
{
    case Useful = 'useful';
    case Ignored = 'ignored';
    case Incorrect = 'incorrect';

    public function label(): string
    {
        return match ($this) {
            self::Useful => 'Useful',
            self::Ignored => 'Ignored',
            self::Incorrect => 'Incorrect',
        };
    }
}
