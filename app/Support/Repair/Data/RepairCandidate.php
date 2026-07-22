<?php

namespace App\Support\Repair\Data;

use Illuminate\Database\Eloquent\Model;

readonly class RepairCandidate
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public Model $subject,
        public string $subjectKey,
        public ?Model $related = null,
        public array $meta = [],
    ) {}

    public function subjectType(): string
    {
        return $this->subject::class;
    }

    public function subjectId(): int
    {
        return (int) $this->subject->getKey();
    }

    public function relatedType(): ?string
    {
        return $this->related !== null ? $this->related::class : null;
    }

    public function relatedId(): ?int
    {
        return $this->related !== null ? (int) $this->related->getKey() : null;
    }
}
