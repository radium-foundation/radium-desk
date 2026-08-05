<?php

namespace App\Support\Platform;

/**
 * Presentation labels for the Platform page top banner (combined domains).
 *
 * Does not change aggregation, cache keys, routes, or APIs.
 * Internal type remains PlatformOverallHealth / platform:overall-health.
 */
final class OverallSystemHealthPresentation
{
    public const TITLE = 'Overall System Health';

    public const DESCRIPTION = 'Combined status of Platform Health (infrastructure), Integration Health (external services), and Operations Snapshot (business workload).';

    public const TOOLTIP = 'Overall System Health combines Platform Health, Integration Health, and Operations Snapshot. It is not infrastructure-only.';
}
