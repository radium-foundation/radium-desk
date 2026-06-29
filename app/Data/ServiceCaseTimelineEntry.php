<?php

namespace App\Data;

use App\Models\Remark;
use Illuminate\Support\Carbon;

readonly class ServiceCaseTimelineEntry
{
    public const TYPE_CREATED = 'created';

    public const TYPE_ASSIGNMENT = 'assignment';

    public const TYPE_REMARK = 'remark';

    public const TYPE_STATUS = 'status';

    public const TYPE_REMARK_DELETED = 'remark_deleted';

    public function __construct(
        public Carbon $occurredAt,
        public string $type,
        public TimelineActor $actor,
        public string $title,
        public ?string $body,
        public ?Remark $remark,
        public string $dedupeKey,
    ) {}
}
