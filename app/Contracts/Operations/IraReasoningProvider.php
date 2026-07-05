<?php

namespace App\Contracts\Operations;

use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRecommendation;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use Illuminate\Support\Carbon;

interface IraReasoningProvider
{
    public function name(): string;

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
    ): IraMorningBriefing;
}
