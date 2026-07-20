<?php

namespace Tests\Unit\Platform;

use App\Data\Platform\PlatformSectionDefinition;
use App\Services\Platform\PlatformSectionRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class PlatformSectionRegistryTest extends TestCase
{
    public function test_ordered_returns_sections_by_priority(): void
    {
        $registry = new PlatformSectionRegistry;
        $registry->register(new PlatformSectionDefinition(id: 'b', title: 'B', priority: 20));
        $registry->register(new PlatformSectionDefinition(id: 'a', title: 'A', priority: 10));

        $ids = array_map(
            fn (PlatformSectionDefinition $section): string => $section->id,
            $registry->ordered(),
        );

        $this->assertSame(['a', 'b'], $ids);
    }

    public function test_get_unknown_section_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PlatformSectionRegistry)->get('missing');
    }
}
