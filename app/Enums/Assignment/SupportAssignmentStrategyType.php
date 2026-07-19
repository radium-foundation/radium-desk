<?php

namespace App\Enums\Assignment;

enum SupportAssignmentStrategyType: string
{
    case RoundRobin = 'round_robin';
    case LeastWorkload = 'least_workload';
    case Performance = 'performance';
    case SkillBased = 'skill_based';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::RoundRobin => 'Round Robin',
            self::LeastWorkload => 'Least Workload',
            self::Performance => 'Performance Based',
            self::SkillBased => 'Skill Based',
            self::Hybrid => 'Hybrid',
        };
    }

    public static function fromConfig(): self
    {
        return self::tryFrom((string) config('support_assignment.strategy', self::RoundRobin->value))
            ?? self::RoundRobin;
    }
}
