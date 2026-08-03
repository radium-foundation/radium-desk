<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Enums\NotificationType;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CommunicationTemplateBladeImporter
{
    public function __construct(
        private readonly NotificationMailTemplateRegistry $mailRegistry,
        private readonly CommunicationTemplateStoreService $store,
    ) {}

    /**
     * @return list<array{
     *     notification_type: string,
     *     name: string,
     *     category: string,
     *     blade_view: string,
     *     subject: string,
     *     required_variables: list<string>,
     *     blade_exists: bool,
     *     imported: bool,
     *     template_key: ?string
     * }>
     */
    public function inventory(): array
    {
        $rows = [];

        foreach ($this->store->inventoryNotificationTypes() as $type) {
            $definition = $this->mailRegistry->resolve($type);
            if ($definition === null) {
                continue;
            }

            $bladePath = resource_path('views/'.str_replace('.', '/', $definition->view).'.blade.php');
            $existing = CommunicationTemplate::query()
                ->where('notification_type', $type->value)
                ->first();

            $rows[] = [
                'notification_type' => $type->value,
                'name' => $this->humanName($type),
                'category' => $this->categoryFor($type)->value,
                'blade_view' => $definition->view,
                'subject' => $definition->subject,
                'required_variables' => $definition->requiredVariables,
                'blade_exists' => File::exists($bladePath),
                'imported' => $existing instanceof CommunicationTemplate,
                'template_key' => $existing?->key,
            ];
        }

        return $rows;
    }

    /**
     * @return array{imported: int, skipped: int, rows: list<array<string, mixed>>}
     */
    public function importAll(User $actor, bool $approve = true): array
    {
        $imported = 0;
        $skipped = 0;
        $rows = [];

        foreach ($this->inventory() as $row) {
            if ($row['imported']) {
                $skipped++;
                $rows[] = [...$row, 'action' => 'skipped'];
                continue;
            }

            $type = NotificationType::from($row['notification_type']);
            $definition = $this->mailRegistry->resolve($type);
            if ($definition === null) {
                $skipped++;
                continue;
            }

            $body = $this->extractBodyHtml($definition->view);
            $greeting = $this->detectGreeting($body);

            $template = $this->store->create([
                'key' => 'email.'.$type->value,
                'name' => $row['name'],
                'category' => $row['category'],
                'channels' => [CommunicationTemplateChannel::Email->value],
                'subject' => $definition->subject,
                'greeting_style' => $greeting->value,
                'body_html' => $body,
                'signature_mode' => CommunicationTemplateSignatureMode::CompanyDefault->value,
                'change_reason' => 'Imported from Blade notification template',
                'status' => $approve
                    ? CommunicationTemplateStatus::Approved->value
                    : CommunicationTemplateStatus::Draft->value,
                'blade_view' => $definition->view,
                'notification_type' => $type->value,
                'is_reply_playbook' => $this->isReplyPlaybook($type),
                'playbook_scope' => 'global',
            ], $actor);

            $imported++;
            $rows[] = [...$row, 'action' => 'imported', 'template_key' => $template->key];
        }

        return compact('imported', 'skipped', 'rows');
    }

    private function categoryFor(NotificationType $type): CommunicationTemplateCategory
    {
        return match ($type) {
            NotificationType::RefundConfirmation => CommunicationTemplateCategory::Refund,
            NotificationType::SupportAppointmentBooked => CommunicationTemplateCategory::Appointment,
            NotificationType::BuyRdService,
            NotificationType::BuyProduct,
            NotificationType::ReviewRequest => CommunicationTemplateCategory::Sales,
            NotificationType::RequestSerialNumber,
            NotificationType::RequestCorrectSerial,
            NotificationType::CustomerWaitingFollowup,
            NotificationType::CallbackSchedule,
            NotificationType::FinalReminderBeforeClosure,
            NotificationType::ServiceCaseClosed,
            NotificationType::DriverInstallationGuide => CommunicationTemplateCategory::Support,
            default => CommunicationTemplateCategory::General,
        };
    }

    private function humanName(NotificationType $type): string
    {
        return Str::of($type->value)->replace('_', ' ')->title()->toString();
    }

    private function isReplyPlaybook(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::RequestSerialNumber,
            NotificationType::CustomerWaitingFollowup,
            NotificationType::CallbackSchedule,
            NotificationType::SupportAppointmentBooked,
            NotificationType::ServiceCaseClosed,
            NotificationType::DriverInstallationGuide,
            NotificationType::RefundConfirmation => true,
            default => false,
        };
    }

    private function extractBodyHtml(string $view): string
    {
        $path = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');

        if (! File::exists($path)) {
            return '<p>{{customer_name}}</p>';
        }

        $raw = File::get($path);

        if (preg_match('/@section\(\s*[\'"]content[\'"]\s*\)(.*?)@endsection/s', $raw, $matches) === 1) {
            $section = trim($matches[1]);
            $section = preg_replace('/@if\([^)]+\)/', '', $section) ?? $section;
            $section = preg_replace('/@endif/', '', $section) ?? $section;
            $section = preg_replace('/\{\{\s*\$([a-zA-Z0-9_]+)\s*\?\?[^}]+\}\}/', '{{$1}}', $section) ?? $section;
            $section = preg_replace('/\{\{\s*\$([a-zA-Z0-9_]+)\s*\}\}/', '{{$1}}', $section) ?? $section;
            $section = preg_replace('/\{\!!\s*\$([a-zA-Z0-9_]+)\s*!!\}/', '{{$1}}', $section) ?? $section;

            return trim($section) !== '' ? trim($section) : '<p></p>';
        }

        return '<p>Imported from '.$view.'</p>';
    }

    private function detectGreeting(string $body): CommunicationTemplateGreetingStyle
    {
        $lower = strtolower(strip_tags($body));

        return match (true) {
            str_contains($lower, 'dear ') => CommunicationTemplateGreetingStyle::DearCustomer,
            str_contains($lower, 'good morning') => CommunicationTemplateGreetingStyle::GoodMorning,
            str_contains($lower, 'good afternoon') => CommunicationTemplateGreetingStyle::GoodAfternoon,
            str_contains($lower, 'good evening') => CommunicationTemplateGreetingStyle::GoodEvening,
            default => CommunicationTemplateGreetingStyle::HelloCustomer,
        };
    }
}
