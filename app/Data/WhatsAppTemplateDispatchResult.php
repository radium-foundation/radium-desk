<?php

namespace App\Data;

use App\Models\WhatsAppTemplateDispatch;

readonly class WhatsAppTemplateDispatchResult
{
    public function __construct(
        public bool $success,
        public ?WhatsAppTemplateDispatch $dispatch = null,
        public ?string $message = null,
    ) {}

    public static function success(WhatsAppTemplateDispatch $dispatch, ?string $message = null): self
    {
        return new self(success: true, dispatch: $dispatch, message: $message);
    }

    public static function failure(?WhatsAppTemplateDispatch $dispatch, string $message): self
    {
        return new self(success: false, dispatch: $dispatch, message: $message);
    }
}
