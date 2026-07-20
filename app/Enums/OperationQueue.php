<?php

namespace App\Enums;

enum OperationQueue: string
{
    case ActionRequired = 'action_required';
    case PendingReview = 'pending_review';
    case Scheduled = 'scheduled';
    case WaitingCustomer = 'waiting_customer';
    case Attention = 'attention';
    case BusinessHold = 'business_hold';
    case Hardware = 'hardware';
    case Completed = 'completed';
    case MyWork = 'my_work';

    public function label(): string
    {
        return config("operations.queues.{$this->value}.label", str($this->value)->headline()->toString());
    }

    public function icon(): string
    {
        return config("operations.queues.{$this->value}.icon", 'bi-inbox');
    }

    public function tone(): string
    {
        return config("operations.queues.{$this->value}.tone", 'primary');
    }

    /**
     * @return list<string>
     */
    public static function adminValues(): array
    {
        return [
            self::Attention->value,
            self::BusinessHold->value,
            self::ActionRequired->value,
            self::Scheduled->value,
            self::WaitingCustomer->value,
            self::Hardware->value,
            self::PendingReview->value,
            self::Completed->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function supportValues(): array
    {
        return [
            self::MyWork->value,
            self::Scheduled->value,
            self::WaitingCustomer->value,
            self::Completed->value,
        ];
    }
}
