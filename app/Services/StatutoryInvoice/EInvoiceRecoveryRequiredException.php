<?php

namespace App\Services\StatutoryInvoice;

use RuntimeException;

/**
 * GENERATE already crossed the WhiteBooks boundary (or may have).
 * Outbox must stay pending for Get-IRN. Must not fail-out or GENERATE again.
 */
final class EInvoiceRecoveryRequiredException extends RuntimeException {}
