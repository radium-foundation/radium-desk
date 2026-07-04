<?php

namespace App\Http\Controllers;

use App\Enums\NotificationLinkSource;
use App\Services\Notifications\NotificationLinkTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupportScheduleRedirectController extends Controller
{
    public function __construct(
        private readonly NotificationLinkTrackingService $linkTrackingService,
    ) {}

    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $source = NotificationLinkSource::tryFrom((string) $request->query('source', ''))
            ?? NotificationLinkSource::WhatsApp;

        $bookingUrl = $this->linkTrackingService->recordClickAndBookingUrl(
            token: $token,
            source: $source,
            request: $request,
        );

        return redirect()->away($bookingUrl);
    }
}
