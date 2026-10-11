<?php

namespace Tests\Unit\Cashfree;

use App\Services\Cashfree\LegacySafeCashfreePaymentLinkService;
use InvalidArgumentException;
use Tests\TestCase;

class LegacySafeCashfreePaymentLinkServiceTest extends TestCase
{
    public function test_loads_exactly_eighty_four_safe_to_link_rows_from_p04_10_196_report(): void
    {
        $path = base_path('storage/app/private/legacy-cf-discovery-P-04-10-196.json');

        if (! is_readable($path)) {
            $this->markTestSkipped('P-04-10-196 discovery report not present locally.');
        }

        $service = $this->app->make(LegacySafeCashfreePaymentLinkService::class);
        $rows = $service->loadEligibleSafeRows($path);

        $this->assertCount(84, $rows);

        $ids = array_column($rows, 'desk_order_id');
        $this->assertSame($ids, array_values(array_unique($ids)));

        foreach ($rows as $row) {
            $this->assertSame('SAFE_TO_LINK', $row['classification']);
        }
    }

    public function test_rejects_report_with_wrong_safe_count(): void
    {
        $service = $this->app->make(LegacySafeCashfreePaymentLinkService::class);
        $tmp = sys_get_temp_dir().'/legacy-cf-discovery-test.json';
        file_put_contents($tmp, json_encode([
            'rows' => [
                ['classification' => 'SAFE_TO_LINK', 'desk_order_id' => 1, 'desk_order_number' => 'RD1'],
            ],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $service->loadEligibleSafeRows($tmp);
    }
}
