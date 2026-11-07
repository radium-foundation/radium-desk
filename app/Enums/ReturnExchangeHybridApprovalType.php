<?php

namespace App\Enums;

enum ReturnExchangeHybridApprovalType: string
{
    case BusinessCategory = 'business_category';
    case Goodwill = 'goodwill';
    case PriceDifference = 'price_difference';
    case Statutory = 'statutory';
    case Finance = 'finance';
    case FinalCompletion = 'final_completion';

    public function label(): string
    {
        return match ($this) {
            self::BusinessCategory => 'Business category',
            self::Goodwill => 'Goodwill exception',
            self::PriceDifference => 'Price difference',
            self::Statutory => 'Statutory document treatment',
            self::Finance => 'Finance reconciliation',
            self::FinalCompletion => 'Final completion',
        };
    }
}
