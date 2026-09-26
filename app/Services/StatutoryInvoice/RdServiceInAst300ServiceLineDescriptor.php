<?php

namespace App\Services\StatutoryInvoice;

/**
 * Canonical AST300 rdservice.in service line description for commerce snapshots.
 */
final class RdServiceInAst300ServiceLineDescriptor
{
    public const INCLUDED_SUPPORT = 'RD Technical Support — included';

    public function billableDescription(string $serial, string $durationLabel): string
    {
        $serial = strtoupper(trim($serial));
        $durationLabel = trim($durationLabel);

        return sprintf(
            'Information technology (IT) consulting & support services (SAC - 998313) - (Sr. No. %s) - %s',
            $serial,
            $durationLabel,
        );
    }
}
