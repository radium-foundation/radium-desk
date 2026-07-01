<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class RemarkMentionParser
{
    /**
     * @return list<string>
     */
    public function mentionedAiAgents(string $body): array
    {
        $normalizedBody = trim($body);

        if ($normalizedBody === '') {
            return [];
        }

        $agents = [];

        if (preg_match('/@IRA\b/u', $normalizedBody) === 1) {
            $agents[] = 'ira';
        }

        return $agents;
    }

    /**
     * @return list<int>
     */
    public function mentionedUserIds(string $body): array
    {
        $normalizedBody = trim($body);

        if ($normalizedBody === '') {
            return [];
        }

        $users = User::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->sortByDesc(fn (User $user): int => strlen((string) $user->name));

        return $this->matchUsersInBody($normalizedBody, $users)
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function matchUsersInBody(string $body, Collection $users): Collection
    {
        $matched = collect();
        $offset = 0;

        while (($atPos = strpos($body, '@', $offset)) !== false) {
            $mentionStart = $atPos + 1;
            $candidate = null;

            foreach ($users as $user) {
                $name = trim((string) $user->name);

                if ($name === '') {
                    continue;
                }

                if (! str_starts_with(substr($body, $mentionStart), $name)) {
                    continue;
                }

                $nextCharacter = $body[$mentionStart + strlen($name)] ?? '';

                if ($nextCharacter !== '' && ! preg_match('/[^\p{L}\p{M}\'.]/u', $nextCharacter)) {
                    continue;
                }

                if ($candidate === null || strlen($name) > strlen((string) $candidate->name)) {
                    $candidate = $user;
                }
            }

            if ($candidate === null) {
                $offset = $mentionStart;

                continue;
            }

            $matched->push($candidate);
            $offset = $mentionStart + strlen((string) $candidate->name);
        }

        return $matched->unique(fn (User $user): int => $user->id);
    }
}
