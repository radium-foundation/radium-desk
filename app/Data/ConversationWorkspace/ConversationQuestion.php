<?php

namespace App\Data\ConversationWorkspace;

use App\Enums\ConversationQuestionKey;

/**
 * Single guided prompt for the Conversation Workspace.
 * Stable contract so IRA can later replace the deterministic resolver.
 */
final class ConversationQuestion
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public readonly ConversationQuestionKey $key,
        public readonly string $prompt,
        public readonly string $inputType = 'text',
        public readonly bool $required = false,
        public readonly bool $skippable = true,
        public readonly array $options = [],
        public readonly ?string $hint = null,
    ) {}

    /**
     * @return array{
     *     key: string,
     *     prompt: string,
     *     input_type: string,
     *     required: bool,
     *     skippable: bool,
     *     options: list<array{value: string, label: string}>,
     *     hint: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'prompt' => $this->prompt,
            'input_type' => $this->inputType,
            'required' => $this->required,
            'skippable' => $this->skippable,
            'options' => $this->options,
            'hint' => $this->hint,
        ];
    }
}
