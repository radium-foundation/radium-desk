<?php

namespace App\Services\Notifications;

use App\Enums\NotificationLinkSource;
use App\Models\Incident;
use App\Models\NotificationLinkClick;
use App\Models\NotificationLinkToken;
use App\Models\Order;
use App\Services\SupportAppointmentUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationLinkTrackingService
{
    public function __construct(
        private readonly SupportAppointmentUrlService $supportAppointmentUrlService,
    ) {}

    public function issueToken(Incident $incident, NotificationLinkSource $source): NotificationLinkToken
    {
        $incident->loadMissing('order');

        return NotificationLinkToken::query()->create([
            'token' => $this->generateUniqueToken(),
            'incident_id' => $incident->id,
            'order_id' => $incident->order_id,
            'source' => $source,
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function trackedSchedulePath(NotificationLinkToken $token): string
    {
        return 'support/schedule/'.$token->token;
    }

    public function trackedScheduleUrl(NotificationLinkToken $token): string
    {
        return url($this->trackedSchedulePath($token).'?source='.$token->source->value);
    }

    public function recordClickAndBookingUrl(
        string $token,
        NotificationLinkSource $source,
        ?Request $request = null,
    ): string {
        $linkToken = NotificationLinkToken::query()
            ->with('incident')
            ->where('token', $token)
            ->first();

        if ($linkToken === null || $linkToken->isExpired()) {
            abort(404);
        }

        if ($linkToken->source !== $source) {
            abort(404);
        }

        NotificationLinkClick::query()->create([
            'notification_link_token_id' => $linkToken->id,
            'incident_id' => $linkToken->incident_id,
            'order_id' => $linkToken->order_id,
            'source' => $source,
            'clicked_at' => now(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        return $this->supportAppointmentUrlService->bookingUrl($linkToken->incident);
    }

    public function clickCount(NotificationLinkSource $source, ?Incident $incident = null): int
    {
        $query = NotificationLinkClick::query()->where('source', $source);

        if ($incident !== null) {
            $query->where('incident_id', $incident->id);
        }

        return $query->count();
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = Str::lower(Str::random(40));
        } while (NotificationLinkToken::query()->where('token', $token)->exists());

        return $token;
    }
}
