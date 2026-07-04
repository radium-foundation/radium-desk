<?php

namespace Tests\Unit\Interakt;

use Tests\TestCase;

class InteraktButtonValuesSerializationTest extends TestCase
{
    public function test_button_values_must_be_json_object_not_array_for_interakt(): void
    {
        $buttonValues = [
            '0' => ['tracking-token-abc123'],
        ];

        $templateWithArray = [
            'name' => 'support_schedule',
            'languageCode' => 'en',
            'buttonValues' => $buttonValues,
        ];

        $templateWithObject = [
            'name' => 'support_schedule',
            'languageCode' => 'en',
            'buttonValues' => (object) $buttonValues,
        ];

        $arrayEncoded = json_encode($templateWithArray);
        $objectEncoded = json_encode($templateWithObject);

        $this->assertStringContainsString('"buttonValues":[["tracking-token-abc123"]]', $arrayEncoded);
        $this->assertStringContainsString('"buttonValues":{"0":["tracking-token-abc123"]}', $objectEncoded);
    }
}
