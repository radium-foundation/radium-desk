<?php

namespace App\Console\Commands;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\User;
use App\Support\BusinessOrderId;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(
    name: 'desk:approve-statutory-auto-issue',
    description: 'Superadmin-only: authorize automatic statutory invoice issuance for a gated service commerce order',
)]
class ApproveStatutoryAutoIssueCommand extends Command
{
    protected $signature = 'desk:approve-statutory-auto-issue
                            {order_id : Desk business order id (e.g. RN292)}
                            {--actor= : Superadmin user id or email performing the approval}';

    public function handle(): int
    {
        $orderId = strtoupper(trim((string) $this->argument('order_id')));
        if ($orderId === '') {
            $this->error('order_id is required.');

            return self::FAILURE;
        }

        $actor = $this->resolveSuperadminActor();
        if ($actor === null) {
            $this->error('Refusing approval without an active superadmin --actor (user id or email).');

            return self::FAILURE;
        }

        $channel = $this->commerceChannelForOrderId($orderId);
        if ($channel !== StatutoryInvoiceChannel::RdServiceNet) {
            $this->error(sprintf(
                'Order %s uses channel %s; this command only records approval for rdservice.net service orders.',
                $orderId,
                $channel->value,
            ));

            return self::FAILURE;
        }

        $commerce = CommerceOrder::query()
            ->where('channel', $channel)
            ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
            ->where('source_id', $orderId)
            ->first();

        if ($commerce === null) {
            $this->error('No commerce order found for '.$orderId.' ('.$channel->value.').');

            return self::FAILURE;
        }

        if ($commerce->statutory_auto_issue_approved_at !== null) {
            $this->info(json_encode([
                'order_id' => $orderId,
                'commerce_order_id' => $commerce->id,
                'already_approved' => true,
                'statutory_auto_issue_approved_at' => $commerce->statutory_auto_issue_approved_at?->toIso8601String(),
                'statutory_auto_issue_approved_by' => $commerce->statutory_auto_issue_approved_by,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $commerce->forceFill([
            'statutory_auto_issue_approved_at' => now(),
            'statutory_auto_issue_approved_by' => $actor->id,
        ])->save();

        $this->info(json_encode([
            'order_id' => $orderId,
            'commerce_order_id' => $commerce->id,
            'approved' => true,
            'statutory_auto_issue_approved_at' => $commerce->fresh()->statutory_auto_issue_approved_at?->toIso8601String(),
            'statutory_auto_issue_approved_by' => $actor->id,
            'actor_email' => $actor->email,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function resolveSuperadminActor(): ?User
    {
        $raw = trim((string) $this->option('actor'));
        if ($raw === '') {
            return null;
        }

        $user = is_numeric($raw)
            ? User::query()->find((int) $raw)
            : User::query()->where('email', $raw)->first();

        if ($user === null || ! $user->is_active) {
            return null;
        }

        if (! $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return null;
        }

        return $user;
    }

    private function commerceChannelForOrderId(string $orderId): StatutoryInvoiceChannel
    {
        if (BusinessOrderId::isRadiumBoxService($orderId)) {
            return StatutoryInvoiceChannel::RadiumBoxCom;
        }

        $parsed = BusinessOrderId::parse($orderId);
        if ($parsed !== null
            && $parsed['owner'] === 'rdservice.net'
            && $parsed['kind'] === 'service') {
            return StatutoryInvoiceChannel::RdServiceNet;
        }

        return StatutoryInvoiceChannel::RdServiceIn;
    }
}
