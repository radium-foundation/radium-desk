<?php

namespace Tests\Unit\Rules;

use App\Rules\ClickToCallMobile;
use Tests\TestCase;

class ClickToCallMobileTest extends TestCase
{
    public function test_rule_accepts_null_and_empty_values(): void
    {
        $rule = new ClickToCallMobile;
        $failures = [];

        $rule->validate('bonvoice_extension', null, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });
        $rule->validate('bonvoice_extension', '', function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertSame([], $failures);
    }

    public function test_rule_accepts_valid_indian_mobile_formats(): void
    {
        $rule = new ClickToCallMobile;
        $failures = [];

        foreach (['9846098460', '+919846098460', '09846098460', '08448423017'] as $value) {
            $rule->validate('bonvoice_extension', $value, function (string $message) use (&$failures): void {
                $failures[] = $message;
            });
        }

        $this->assertSame([], $failures);
    }

    public function test_rule_rejects_invalid_digit_lengths(): void
    {
        $rule = new ClickToCallMobile;
        $failures = [];

        foreach (['12345', '12345678901', 'abc'] as $value) {
            $rule->validate('bonvoice_extension', $value, function (string $message) use (&$failures, $value): void {
                $failures[$value] = $message;
            });
        }

        $this->assertCount(3, $failures);
        $this->assertStringContainsString('10-digit', $failures['12345']);
    }
}
