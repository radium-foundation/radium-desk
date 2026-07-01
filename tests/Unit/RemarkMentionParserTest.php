<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\RemarkMentionParser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemarkMentionParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_it_matches_full_active_user_names_in_remark_body(): void
    {
        $damini = User::factory()->create(['name' => 'Damini Patel', 'is_active' => true]);
        User::factory()->create(['name' => 'Damini', 'is_active' => true]);

        $parser = app(RemarkMentionParser::class);

        $ids = $parser->mentionedUserIds('Customer confirmed @Damini Patel will call back.');

        $this->assertSame([$damini->id], $ids);
    }

    public function test_it_ignores_inactive_users(): void
    {
        User::factory()->create(['name' => 'Inactive User', 'is_active' => false]);

        $parser = app(RemarkMentionParser::class);

        $this->assertSame([], $parser->mentionedUserIds('Please follow up with @Inactive User.'));
    }

    public function test_it_detects_ira_ai_mention(): void
    {
        $parser = app(RemarkMentionParser::class);

        $this->assertSame(['ira'], $parser->mentionedAiAgents('Please review @IRA summary.'));
        $this->assertSame([], $parser->mentionedAiAgents('No AI mention here.'));
    }
}
