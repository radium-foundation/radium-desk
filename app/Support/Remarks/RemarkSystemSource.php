<?php

namespace App\Support\Remarks;

/**
 * Identifies which workflow created a system remark (audit metadata only).
 */
final class RemarkSystemSource
{
    public const WORKSPACE_ASSIGN = 'workspace_assign';

    public const WORKSPACE_CLOSE = 'workspace_close';

    public const WORKSPACE_ESCALATE = 'workspace_escalate';

    public const STATUS_CHANGE = 'status_change';

    public const REOPEN = 'reopen';

    public const WHATSAPP_DISPATCH = 'whatsapp_dispatch';

    public const CUSTOMER_WAITING_AUTO_CLOSE = 'customer_waiting_auto_close';

    public const CUSTOMER_WAITING_LEGACY_CLEANUP = 'customer_waiting_legacy_cleanup';

    public const REFUND_CLOSE = 'refund_close';

    public const INQUIRY_SPAM_CLEANUP = 'inquiry_spam_cleanup';
}
