<?php

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingServiceRequestMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_hits_cache_store_once_per_request_instance(): void
    {
        Setting::query()->create([
            'key' => 'sla.normal_overdue_hours',
            'value' => '48',
        ]);

        Cache::flush();
        config(['cache.default' => 'database']);

        $service = app(SettingService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(48, $service->getInt('sla.normal_overdue_hours', 0));
        $this->assertSame(48, $service->getInt('sla.normal_overdue_hours', 0));
        $this->assertSame(24, $service->getInt('sla.normal_warning_hours', 24));

        $cacheSelects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from `cache`')
                || str_contains(strtolower($query['query']), 'from "cache"'))
            ->count();

        $this->assertLessThanOrEqual(
            2,
            $cacheSelects,
            'Expected at most one cache read (+ optional expiration cleanup), got '.$cacheSelects,
        );
    }

    public function test_forget_clears_request_memo(): void
    {
        $service = app(SettingService::class);

        Setting::query()->create([
            'key' => 'general.company_name',
            'value' => 'Before',
        ]);

        $this->assertSame('Before', $service->get('general.company_name'));

        Setting::query()->where('key', 'general.company_name')->update(['value' => 'After']);
        $service->forget();

        $this->assertSame('After', $service->get('general.company_name'));
    }
}
