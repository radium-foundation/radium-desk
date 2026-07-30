<?php

namespace App\Enums;

enum ConversationDisposition: string
{
    case QualifiedLead = 'qualified_lead';
    case ExistingCustomer = 'existing_customer';
    case InformationOnly = 'information_only';
    case WrongNumber = 'wrong_number';
    case NotInterested = 'not_interested';
    case CallbackRequired = 'callback_required';

    public function label(): string
    {
        return config(
            "conversation_workspace.dispositions.{$this->value}",
            str($this->value)->headline()->toString(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
