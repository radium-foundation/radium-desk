<?php

namespace Tests\Unit\CallCapture;

use App\Enums\ConversationQuestionKey;
use App\Services\CallCapture\CallCaptureQuestionResolver;
use Tests\TestCase;

class CallCaptureQuestionResolverTest extends TestCase
{
    public function test_returns_brand_question_for_printer_need(): void
    {
        $resolver = new CallCaptureQuestionResolver;

        $question = $resolver->nextQuestion('Need help with printer setup', null, []);

        $this->assertNotNull($question);
        $this->assertSame(ConversationQuestionKey::Brand, $question->key);
        $this->assertSame('Which brand?', $question->prompt);
    }

    public function test_skips_answered_follow_up_and_returns_null_when_done(): void
    {
        $resolver = new CallCaptureQuestionResolver;

        $question = $resolver->nextQuestion('printer issue', null, [
            'brand' => 'HP',
        ]);

        $this->assertNull($question);
    }

    public function test_returns_model_for_laptop_need(): void
    {
        $resolver = new CallCaptureQuestionResolver;

        $question = $resolver->nextQuestion('Laptop not charging', null, []);

        $this->assertNotNull($question);
        $this->assertSame(ConversationQuestionKey::Model, $question->key);
    }

    public function test_returns_order_id_for_existing_order_need(): void
    {
        $resolver = new CallCaptureQuestionResolver;

        $question = $resolver->nextQuestion('Where is my order delivery?', null, []);

        $this->assertNotNull($question);
        $this->assertSame(ConversationQuestionKey::OrderId, $question->key);
    }

    public function test_returns_null_when_need_has_no_follow_up(): void
    {
        $resolver = new CallCaptureQuestionResolver;

        $this->assertNull($resolver->nextQuestion('General product question', null, []));
    }

    public function test_returns_null_for_blank_need(): void
    {
        $resolver = new CallCaptureQuestionResolver;

        $this->assertNull($resolver->nextQuestion('   ', null, []));
    }
}
