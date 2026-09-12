<?php

namespace App\Services\StatutoryInvoice;

use RuntimeException;

/**
 * Provider rejected the attempt in a way that is safe to retry.
 * Do not use this for timeouts after the request may have been accepted.
 */
final class EInvoiceTemporaryFailureException extends RuntimeException {}
