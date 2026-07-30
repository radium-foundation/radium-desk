<?php

namespace App\Services\CallCapture;

use App\Data\ConversationWorkspace\ConversationQuestion;
use App\Enums\ConversationQuestionKey;

/**
 * Decides the next guided question after Customer Need is known.
 *
 * Deterministic for Phase 1. Swap internals for IRA later without changing
 * the Conversation Workspace UI contract.
 */
class CallCaptureQuestionResolver
{
    /**
     * @param  array<string, mixed>  $captured
     */
    public function nextQuestion(
        string $customerNeed,
        ?string $currentStep,
        array $captured = [],
    ): ?ConversationQuestion {
        $need = mb_strtolower(trim($customerNeed));

        if ($need === '') {
            return null;
        }

        $answered = array_filter(
            $captured,
            static fn ($value) => filled($value) || $value === true || $value === false,
        );

        foreach ($this->candidatesForNeed($need) as $question) {
            $key = $question->key->value;

            if (array_key_exists($key, $answered)) {
                continue;
            }

            if ($currentStep !== null && $currentStep !== '' && $key === $currentStep) {
                continue;
            }

            return $question;
        }

        return null;
    }

    /**
     * @return list<ConversationQuestion>
     */
    private function candidatesForNeed(string $need): array
    {
        $questions = [];

        if ($this->mentions($need, ['printer', 'print', 'scan', 'scanner', 'mfp'])) {
            $questions[] = new ConversationQuestion(
                key: ConversationQuestionKey::Brand,
                prompt: 'Which brand?',
                inputType: 'text',
                required: false,
                skippable: true,
                hint: 'Printer / scanner',
            );
        }

        if ($this->mentions($need, ['laptop', 'notebook', 'macbook', 'chromebook'])) {
            $questions[] = new ConversationQuestion(
                key: ConversationQuestionKey::Model,
                prompt: 'Which model?',
                inputType: 'text',
                required: false,
                skippable: true,
                hint: 'Laptop',
            );
        }

        if ($this->mentions($need, ['order', 'tracking', 'dispatch', 'delivery', 'shipment'])) {
            $questions[] = new ConversationQuestion(
                key: ConversationQuestionKey::OrderId,
                prompt: 'Do they have an Order ID?',
                inputType: 'text',
                required: false,
                skippable: true,
                hint: 'Existing order',
            );
        }

        return $questions;
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
