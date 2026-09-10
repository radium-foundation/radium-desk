<?php

namespace Tests\Support;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;

final class FakeEInvoiceGateway implements EInvoiceGateway
{
    public int $submitCount = 0;

    public int $fetchCount = 0;

    /** @var list<int> */
    public array $submittedInvoiceIds = [];

    public bool $crashOnSubmit = false;

    /** @var (\Closure(): void)|null */
    public $beforeSubmit = null;

    /** @var list<EInvoiceSubmitResult> */
    private array $results = [];

    /** @var list<EInvoiceSubmitResult> */
    private array $fetchQueue = [];

    private EInvoiceSubmitResult $fetchDefault;

    public function __construct(
        private EInvoiceSubmitResult $default,
        ?EInvoiceSubmitResult $fetchDefault = null,
    ) {
        $this->fetchDefault = $fetchDefault ?? EInvoiceSubmitResult::ambiguous(
            'fake',
            ['reason' => 'not_found'],
        );
    }

    public static function succeeding(
        string $irn = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
    ): self {
        return new self(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: $irn,
            ackNo: 'ACK-1001',
            ackDate: '2026-09-10 10:00:00',
            signedQr: 'signed-qr-token',
            signedInvoice: 'eyJhbGciOiJ0ZXN0In0.eyJ0ZXN0Ijoic2lnbmVkLWludm9pY2UifQ.test-signature',
            correlationId: 'corr-1',
        ));
    }

    public function queue(EInvoiceSubmitResult $result): self
    {
        $this->results[] = $result;

        return $this;
    }

    public function withFetch(EInvoiceSubmitResult $result): self
    {
        $this->fetchDefault = $result;

        return $this;
    }

    public function queueFetch(EInvoiceSubmitResult $result): self
    {
        $this->fetchQueue[] = $result;

        return $this;
    }

    public function crashOnSubmit(bool $crash = true): self
    {
        $this->crashOnSubmit = $crash;

        return $this;
    }

    /**
     * @param  \Closure(): void  $callback
     */
    public function beforeSubmit(\Closure $callback): self
    {
        $this->beforeSubmit = $callback;

        return $this;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function submit(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): EInvoiceSubmitResult
    {
        $this->submitCount++;
        $this->submittedInvoiceIds[] = $invoice->id;

        if ($this->beforeSubmit !== null) {
            ($this->beforeSubmit)();
        }

        if ($this->crashOnSubmit) {
            throw new \RuntimeException('simulated crash after WhiteBooks GENERATE left the process');
        }

        if ($this->results !== []) {
            return array_shift($this->results);
        }

        return $this->default;
    }

    public function fetchExisting(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): EInvoiceSubmitResult
    {
        $this->fetchCount++;

        if ($this->fetchQueue !== []) {
            return array_shift($this->fetchQueue);
        }

        return $this->fetchDefault;
    }

    public function cancel(StatutoryInvoice $invoice, string $reason): void
    {
        // Cancellation is unsupported in this foundation.
    }
}
