<?php

namespace App\Data;

use App\Enums\NotificationType;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;

readonly class NotificationMessage
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public NotificationType $type,
        public mixed $customer,
        public Incident $incident,
        public ?string $subject = null,
        public ?string $template = null,
        public array $variables = [],
        public array $attachments = [],
        public array $metadata = [],
        public ?User $actor = null,
        public ?Request $httpRequest = null,
    ) {}
}
