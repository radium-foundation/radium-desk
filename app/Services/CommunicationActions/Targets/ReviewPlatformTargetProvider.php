<?php

namespace App\Services\CommunicationActions\Targets;

use App\Contracts\CommunicationActions\CommunicationActionTargetProvider;
use App\Data\CommunicationActions\CommunicationActionTarget;
use App\Enums\CommunicationActionKey;
use App\Models\Incident;

final class ReviewPlatformTargetProvider implements CommunicationActionTargetProvider
{
    public function supports(CommunicationActionKey $key): bool
    {
        return $key === CommunicationActionKey::ReviewRequest;
    }

    public function targetGroupLabel(): string
    {
        return 'Review Platforms';
    }

    public function targets(Incident $incident): array
    {
        return collect(config('communication_actions.review_platforms', []))
            ->map(function (array $platform): ?CommunicationActionTarget {
                $url = trim((string) ($platform['url'] ?? ''));
                $key = trim((string) ($platform['key'] ?? ''));
                $name = trim((string) ($platform['name'] ?? ''));

                if ($url === '' || $key === '' || $name === '') {
                    return null;
                }

                return new CommunicationActionTarget(
                    value: $key,
                    label: $name,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    public function defaultTargetValue(Incident $incident): ?string
    {
        $targets = $this->targets($incident);

        if ($targets === []) {
            return null;
        }

        $defaultReviewUrl = trim((string) config('communication_actions.urls.review', ''));

        if ($defaultReviewUrl !== '') {
            foreach (config('communication_actions.review_platforms', []) as $platform) {
                $url = trim((string) ($platform['url'] ?? ''));
                $key = trim((string) ($platform['key'] ?? ''));

                if ($url !== '' && $key !== '' && $url === $defaultReviewUrl) {
                    return $key;
                }
            }
        }

        return $targets[0]->value;
    }
}
