<?php

namespace App\Contracts\Platform;

use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Models\User;

interface PlatformCardProvider
{
    public function definition(): PlatformCardDefinition;

    public function authorize(User $viewer): bool;

    public function load(User $viewer): PlatformCardPayload;

    public function refresh(User $viewer): PlatformCardPayload;

    /** @deprecated Use definition()->id */
    public function key(): string;

    /** @deprecated Use definition()->title */
    public function title(): string;

    /** @deprecated Use definition()->section */
    public function section(): string;

    /** @deprecated Use definition()->permission */
    public function permission(): ?string;

    /** @deprecated Use definition()->priority */
    public function sortOrder(): int;

    /** @deprecated Use load() */
    public function payload(User $viewer): PlatformCardPayload;
}
