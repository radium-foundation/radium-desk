<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\EInvoiceUqcMapper;
use Tests\TestCase;

class EInvoiceUqcMapperTest extends TestCase
{
    public function test_p181_master_codes_are_accepted(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => 'GGK', 'gap' => null], $mapper->resolve('GGK'));
        $this->assertSame(['code' => 'MLT', 'gap' => null], $mapper->resolve('mlt'));
        $this->assertSame(['code' => 'PCS', 'gap' => null], $mapper->resolve('PCS'));
        $this->assertSame(['code' => 'PCS', 'gap' => null], $mapper->resolve('pcs'));
        $this->assertContains('GGK', EInvoiceUqcMapper::codes());
        $this->assertContains('MLT', EInvoiceUqcMapper::codes());
        $this->assertContains('PCS', EInvoiceUqcMapper::codes());
    }

    public function test_ggr_is_rejected_as_not_on_nic_master(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => null, 'gap' => 'unsupported_uqc'], $mapper->resolve('GGR'));
        $this->assertNotContains('GGR', EInvoiceUqcMapper::codes());
    }

    public function test_existing_verified_codes_remain_accepted(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => 'NOS', 'gap' => null], $mapper->resolve('NOS'));
        $this->assertSame(['code' => 'UNT', 'gap' => null], $mapper->resolve('unt'));
        $this->assertSame(['code' => 'KGS', 'gap' => null], $mapper->resolve('kgs'));
        $this->assertSame(['code' => 'BOX', 'gap' => null], $mapper->resolve('BOX'));
    }

    public function test_unknown_code_is_rejected(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => null, 'gap' => 'unsupported_uqc'], $mapper->resolve('widget'));
        $this->assertSame(['code' => null, 'gap' => 'unsupported_uqc'], $mapper->resolve('XYZ'));
    }

    public function test_missing_uqc_is_not_defaulted_to_pcs_or_nos(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => null, 'gap' => 'missing_uqc'], $mapper->resolve(null));
        $this->assertSame(['code' => null, 'gap' => 'missing_uqc'], $mapper->resolve(''));
        $this->assertNull($mapper->snapshot(null, null));
        $this->assertNull($mapper->snapshot('widget', 'NOS'));
        $this->assertSame('NOS', $mapper->snapshot(null, 'NOS'));
        $this->assertSame('PCS', $mapper->snapshot('pcs', 'NOS'));
        $this->assertSame(
            ['code' => 'PCS', 'gap' => null],
            $mapper->resolveLineOrCatalog(null, 'PCS'),
        );
        $this->assertSame(['code' => 'NOS', 'gap' => null], $mapper->resolveLineOrCatalog('NOS', 'PCS'));
        $this->assertSame(
            ['code' => null, 'gap' => 'unsupported_uqc'],
            $mapper->resolveLineOrCatalog('widget', 'PCS'),
        );
        $this->assertSame(
            ['code' => null, 'gap' => 'unsupported_uqc'],
            $mapper->resolveLineOrCatalog(null, 'widget'),
        );
        $this->assertSame(
            ['code' => null, 'gap' => 'missing_uqc'],
            $mapper->resolveLineOrCatalog(null, null),
        );
    }
}
