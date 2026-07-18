<?php

namespace App\Contracts\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;

interface InboundEmailProvider
{
    /**
     * Pull new inbound messages from the provider.
     * Gmail live sync uses historyId incremental retrieval (no historical backfill).
     *
     * @return list<NormalizedInboundEmail>
     */
    public function pull(): array;
}
