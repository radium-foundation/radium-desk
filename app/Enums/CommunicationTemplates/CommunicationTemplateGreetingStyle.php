<?php

namespace App\Enums\CommunicationTemplates;

enum CommunicationTemplateGreetingStyle: string
{
    case DearCustomer = 'dear_customer';
    case HelloCustomer = 'hello_customer';
    case GoodMorning = 'good_morning';
    case GoodAfternoon = 'good_afternoon';
    case GoodEvening = 'good_evening';
    case CompanyDefault = 'company_default';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::DearCustomer => 'Dear {{customer_name}}',
            self::HelloCustomer => 'Hello {{customer_name}}',
            self::GoodMorning => 'Good Morning',
            self::GoodAfternoon => 'Good Afternoon',
            self::GoodEvening => 'Good Evening',
            self::CompanyDefault => 'Company Default',
            self::None => 'No greeting',
        };
    }

    public function render(array $variables = []): string
    {
        $name = trim((string) ($variables['customer_name'] ?? 'Customer'));

        return match ($this) {
            self::DearCustomer => 'Dear '.$name.',',
            self::HelloCustomer => 'Hello '.$name.',',
            self::GoodMorning => 'Good Morning,',
            self::GoodAfternoon => 'Good Afternoon,',
            self::GoodEvening => 'Good Evening,',
            self::CompanyDefault => 'Hello '.$name.',',
            self::None => '',
        };
    }
}
