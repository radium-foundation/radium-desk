<?php

namespace App\Services\Operations;

use App\Contracts\Operations\IraReasoningProvider;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRecommendation;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use Illuminate\Support\Carbon;
use RuntimeException;

class OpenAIReasoningProvider implements IraReasoningProvider
{
    public function name(): string
    {
        return 'openai';
    }

    /**
     * @param  list<IraOperationalRisk>  $risks
     * @param  list<IraOperationalRecommendation>  $recommendations
     */
    public function generateBriefing(
        IraOperationalSnapshotData $snapshot,
        ?IraOperationalSnapshotData $yesterday,
        array $risks,
        array $recommendations,
        ?Carbon $at = null,
    ): IraMorningBriefing {
        throw new RuntimeException('OpenAI reasoning provider is not enabled yet.');
    }
}
