<?php

namespace App\Services\Operations;

use App\Contracts\Operations\IraReasoningProvider;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\IraInsightFeedbackResponse;
use App\Enums\IraInsightType;
use App\Models\IraInsightFeedback;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IraInsightFeedbackService
{
    public function record(
        string $insightKey,
        IraInsightType $insightType,
        IraInsightFeedbackResponse $response,
        array $insightPayload,
        ?User $user = null,
        ?Carbon $at = null,
    ): IraInsightFeedback {
        $at ??= now();

        return IraInsightFeedback::query()->create([
            'insight_key' => $insightKey,
            'insight_type' => $insightType->value,
            'insight_payload' => $insightPayload,
            'response' => $response,
            'user_id' => $user?->id,
            'responded_at' => $at,
        ]);
    }

    public function insightKey(IraInsightType $type, string $identifier): string
    {
        return Str::limit($type->value.':'.$identifier, 128, '');
    }

    /**
     * @return array<string, int>
     */
    public function responseCounts(?Carbon $since = null): array
    {
        $query = IraInsightFeedback::query();

        if ($since !== null) {
            $query->where('responded_at', '>=', $since);
        }

        return $query
            ->selectRaw('response, count(*) as total')
            ->groupBy('response')
            ->pluck('total', 'response')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
