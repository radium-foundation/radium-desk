<?php

namespace App\Enums;

enum OperationQueue: string
{
    case ActionRequired = 'action_required';
    case Scheduled = 'scheduled';
    case WaitingCustomer = 'waiting_customer';
    case Attention = 'attention';
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
            self::ActionRequired->value,
            self::Scheduled->value,
            self::WaitingCustomer->value,
            self::Attention->value,
            self::Hardware->value,
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
