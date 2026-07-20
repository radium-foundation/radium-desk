<?php

namespace App\Enums;

enum ExecutiveMetricPolarity: string
{
    case HigherBetter = 'higher_better';
    case LowerBetter = 'lower_better';
    case Neutral = 'neutral';
}
