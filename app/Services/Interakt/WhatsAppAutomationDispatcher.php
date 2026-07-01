<?php

namespace App\Services\Interakt;

use App\Data\WhatsAppTemplateDispatchResult;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;

class WhatsAppAutomationDispatcher
{
    public function __construct(
        private readonly WhatsAppTemplateDispatcher $templateDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        WhatsAppTemplate $template,
        Incident $incident,
        WhatsAppTemplateTriggerSource $triggerSource,
        ?User $actor = null,
        array $context = [],
        ?Request $request = null,
    ): WhatsAppTemplateDispatchResult {
        return $this->templateDispatcher->dispatch(
            template: $template,
            incident: $incident,
            actor: $actor,
            triggerSource: $triggerSource,
            context: $context,
            request: $request,
        );
    }
}
