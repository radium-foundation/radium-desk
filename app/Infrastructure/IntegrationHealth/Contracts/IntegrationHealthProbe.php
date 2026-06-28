<?php

namespace App\Infrastructure\IntegrationHealth\Contracts;

use App\Infrastructure\IntegrationHealth\IntegrationHealthSnapshot;

/**
 * Contract for integration health probes.
 *
 * Each external integration (Cashfree, RadiumBox, WhatsApp, etc.) implements this
 * interface so health can be aggregated without coupling to business services.
 */
interface IntegrationHealthProbe
{
    public function key(): string;

    public function label(): string;

    public function probe(): IntegrationHealthSnapshot;
}
