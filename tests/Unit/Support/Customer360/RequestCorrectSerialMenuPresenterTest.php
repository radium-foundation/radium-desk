<?php

namespace Tests\Unit\Support\Customer360;

use App\Support\Customer360\RequestCorrectSerialMenuPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestCorrectSerialMenuPresenterTest extends TestCase
{
    #[DataProvider('menuStateProvider')]
    public function test_resolve_menu_state(bool $canRequest, array $requestState, array $expected): void
    {
        $menu = RequestCorrectSerialMenuPresenter::resolve($canRequest, $requestState);

        $this->assertSame($expected['status'], $menu['status']);
        $this->assertSame($expected['label'], $menu['label']);
        $this->assertSame($expected['type'], $menu['type']);
        $this->assertSame($expected['visible'], $menu['visible']);
        $this->assertSame($expected['enabled'], $menu['enabled']);
    }

    public static function menuStateProvider(): array
    {
        return [
            'available' => [
                true,
                ['requested' => false],
                [
                    'status' => 'available',
                    'label' => 'Request Serial',
                    'type' => 'trigger',
                    'visible' => true,
                    'enabled' => true,
                ],
            ],
            'pending' => [
                false,
                ['requested' => true, 'requested_at_label' => '11 Jul, 09:00 PM'],
                [
                    'status' => 'pending',
                    'label' => 'Serial Requested',
                    'type' => 'status',
                    'visible' => true,
                    'enabled' => false,
                ],
            ],
            're-request' => [
                true,
                ['requested' => true, 'requested_at_label' => '11 Jul, 09:00 PM'],
                [
                    'status' => 're-request',
                    'label' => 'Re-request Serial',
                    'type' => 'trigger',
                    'visible' => true,
                    'enabled' => true,
                ],
            ],
            'hidden' => [
                false,
                ['requested' => false],
                [
                    'status' => 'hidden',
                    'label' => '',
                    'type' => 'hidden',
                    'visible' => false,
                    'enabled' => false,
                ],
            ],
        ];
    }
}
