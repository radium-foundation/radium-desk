<?php

namespace App\Console\Commands;

use App\Enums\TeamBroadcastAudience;
use App\Models\User;
use App\Services\Operations\TeamTelegramBroadcastService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;

class BroadcastTeamTelegramCommand extends Command
{
    protected $signature = 'telegram:broadcast-team
        {message : Announcement message body}
        {--subject= : Optional announcement subject}
        {--audience=all_team : Audience (all_team, operations_team, selected)}
        {--users= : Comma-separated user IDs when audience is selected}';

    protected $description = 'Broadcast a team announcement via Telegram (superadmin only)';

    public function handle(TeamTelegramBroadcastService $broadcastService): int
    {
        $sender = $this->resolveSender();

        if ($sender === null) {
            $this->error('No active superadmin found to send the broadcast.');

            return self::FAILURE;
        }

        $audience = TeamBroadcastAudience::tryFrom((string) $this->option('audience'))
            ?? TeamBroadcastAudience::AllTeam;

        $selectedUserIds = $this->parseUserIds((string) ($this->option('users') ?? ''));

        if ($audience === TeamBroadcastAudience::Selected && $selectedUserIds === []) {
            $this->error('Provide --users when audience is selected.');

            return self::FAILURE;
        }

        $result = $broadcastService->broadcast(
            sender: $sender,
            message: (string) $this->argument('message'),
            audience: $audience,
            selectedUserIds: $selectedUserIds,
            subject: $this->option('subject') !== null ? (string) $this->option('subject') : null,
        );

        $this->info(sprintf(
            'Broadcast complete. %d recipient(s), %d sent, %d skipped, %d failed.',
            $result['recipients'],
            $result['sent'],
            $result['skipped'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }

    private function resolveSender(): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', RolePermissionSeeder::ROLE_SUPERADMIN))
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<int>
     */
    private function parseUserIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            fn (int $id): bool => $id > 0,
        ));
    }
}
