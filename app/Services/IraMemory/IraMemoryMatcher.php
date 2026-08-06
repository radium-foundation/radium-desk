<?php

namespace App\Services\IraMemory;

use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Canonical email matcher over ira_memories (status=active).
 */
class IraMemoryMatcher
{
    /**
     * @return list<IraMemoryMatch>
     */
    public function matchForEmailMessage(
        IncomingEmailMessage $message,
        IraMemorySource $source = IraMemorySource::Email,
    ): array {
        $candidates = $this->candidateValues($message);

        if ($candidates === []) {
            return [];
        }

        /** @var Collection<int, IraMemory> $memories */
        $memories = IraMemory::query()
            ->where('status', IraMemoryStatus::Active->value)
            ->where('source', $source->value)
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates as $index => [$kind, $value]) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($nested) use ($kind, $value): void {
                        $nested->where('pattern_kind', $kind->value)
                            ->where('pattern_value', $value);
                    });
                }
            })
            ->orderByDesc('confidence')
            ->orderByDesc('times_used')
            ->orderBy('id')
            ->get();

        $matches = [];

        foreach ($memories as $memory) {
            $matchedValue = $this->matchedValueForMemory($memory, $candidates);

            if ($matchedValue === null) {
                continue;
            }

            $matches[] = new IraMemoryMatch(
                memory: $memory,
                matchedOn: $memory->pattern_kind?->label() ?? 'Pattern',
                matchedValue: $matchedValue,
            );
        }

        return $matches;
    }

    /**
     * @return list<array{0: IraMemoryPatternKind, 1: string}>
     */
    public function candidateValues(IncomingEmailMessage $message): array
    {
        $candidates = [];

        $sender = Str::lower(trim((string) $message->from_email));

        if ($sender !== '') {
            $candidates[] = [IraMemoryPatternKind::Sender, $sender];
        }

        $domain = $this->senderDomain($message->from_email);

        if ($domain !== null) {
            $candidates[] = [IraMemoryPatternKind::SenderDomain, $domain];
        }

        $subjectPattern = $this->normalizeSubjectPattern($message->subject);

        if ($subjectPattern !== '') {
            $candidates[] = [IraMemoryPatternKind::SubjectPattern, $subjectPattern];
        }

        $mailbox = Str::lower(trim((string) $message->mailbox));

        if ($mailbox !== '') {
            $candidates[] = [IraMemoryPatternKind::Mailbox, $mailbox];
        }

        $haystack = Str::lower(trim(
            ((string) $message->subject).' '.((string) $message->preview),
        ));

        if ($haystack !== '') {
            $keywordMemories = IraMemory::query()
                ->where('status', IraMemoryStatus::Active->value)
                ->where('pattern_kind', IraMemoryPatternKind::Keyword->value)
                ->get(['id', 'pattern_kind', 'pattern_value']);

            foreach ($keywordMemories as $keywordMemory) {
                $keyword = Str::lower(trim((string) $keywordMemory->pattern_value));

                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    $candidates[] = [IraMemoryPatternKind::Keyword, $keyword];
                }
            }
        }

        return $candidates;
    }

    public function normalizeSubjectPattern(?string $subject): string
    {
        $value = Str::lower(trim((string) $subject));
        $value = preg_replace('/^(re|fwd|fw)\s*:\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/\d+/', '#', $value) ?? $value;

        return Str::limit(trim($value), 200, '');
    }

    public function senderDomain(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        return Str::after($email, '@') ?: null;
    }

    /**
     * @param  list<array{0: IraMemoryPatternKind, 1: string}>  $candidates
     */
    private function matchedValueForMemory(IraMemory $memory, array $candidates): ?string
    {
        foreach ($candidates as [$kind, $value]) {
            if ($kind === $memory->pattern_kind && $value === $memory->pattern_value) {
                return $value;
            }
        }

        return null;
    }
}
