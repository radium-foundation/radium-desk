<?php

namespace App\Support\Dashboard\Contracts;

use App\Models\Incident;
use Illuminate\Support\Carbon;

/**
 * Extension point for future dashboard "Attention Score" sorting.
 *
 * Planned signal families (not yet implemented):
 * - Multiple missed IVR calls
 * - Incoming IVR received
 * - Unread email
 * - WhatsApp / Telegram activity
 * - Customer replies
 * - Recent operator actions
 */
interface DashboardAttentionScoreCalculator
{
    /**
     * Higher scores indicate cases that should appear earlier in the queue.
     */
    public function score(Incident $incident, ?Carbon $now = null): int;
}
