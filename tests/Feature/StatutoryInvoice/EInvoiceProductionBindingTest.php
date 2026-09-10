<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Services\StatutoryInvoice\EInvoiceIssuancePolicy;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Services\StatutoryInvoice\StatutoryInvoiceAccountingPolicy;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksEInvoiceGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EInvoiceProductionBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config([
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::HardwareOnly->value,
        ]);
    }

    public function test_defaults_remain_null_gateway_worker_off_and_hardware_only(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
        $this->assertSame('hardware_only', config('statutory_invoices.einvoice.issuance_policy'));
        $this->assertSame(EInvoiceIssuancePolicyMode::HardwareOnly, app(EInvoiceIssuancePolicy::class)->mode());
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        Http::assertNothingSent();
    }

    public function test_whitebooks_gateway_binds_when_provider_is_whitebooks(): void
    {
        config(['statutory_invoices.einvoice.provider' => 'whitebooks']);

        $this->assertInstanceOf(WhitebooksEInvoiceGateway::class, app(EInvoiceGateway::class));
        Http::assertNothingSent();
    }

    public function test_non_whitebooks_provider_stays_on_null_gateway(): void
    {
        config(['statutory_invoices.einvoice.provider' => 'nic']);

        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        Http::assertNothingSent();
    }

    public function test_auto_issue_on_pos_complete_still_aborts(): void
    {
        config(['statutory_invoices.auto_issue_on_pos_complete' => true]);

        $this->expectException(ValidationException::class);
        app(StatutoryInvoiceAccountingPolicy::class)->assertMustNotAutoIssueOnPosComplete();
    }
}
