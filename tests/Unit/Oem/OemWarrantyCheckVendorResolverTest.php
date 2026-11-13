<?php

namespace Tests\Unit\Oem;

use App\Models\Order;
use App\Support\Oem\OemWarrantyCheckVendor;
use App\Support\Oem\OemWarrantyCheckVendorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OemWarrantyCheckVendorResolverTest extends TestCase
{
    private OemWarrantyCheckVendorResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new OemWarrantyCheckVendorResolver;
    }

    /**
     * @return array<string, array{0: string, 1: OemWarrantyCheckVendor|null}>
     */
    public static function modelProvider(): array
    {
        return [
            'mis 100' => ['MIS 100', OemWarrantyCheckVendor::Mantra],
            'mfs 110' => ['MFS 110', OemWarrantyCheckVendor::Mantra],
            'mso e3' => ['MSO E3', OemWarrantyCheckVendor::Morpho],
            'marc 11' => ['MARC 11', OemWarrantyCheckVendor::Morpho],
            'fm 220' => ['FM 220', null],
            'pb 1000' => ['PB 1000', null],
            'unknown' => ['Unknown Widget', null],
        ];
    }

    #[DataProvider('modelProvider')]
    public function test_resolves_supported_models(string $model, ?OemWarrantyCheckVendor $expected): void
    {
        $order = new Order([
            'device_model' => $model,
        ]);

        $this->assertSame($expected, $this->resolver->resolveForOrder($order));
    }
}
