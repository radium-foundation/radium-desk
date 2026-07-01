<?php

namespace App\Data;

readonly class NotificationMailTemplateDefinition
{
    /**
     * @param  list<string>  $requiredVariables
     */
    public function __construct(
        public string $subject,
        public string $view,
        public array $requiredVariables = [],
    ) {}
}
