<?php

namespace Tests\Unit;

use App\Data\RemarkMetadata;
use Tests\TestCase;

class RemarkMetadataTest extends TestCase
{
    public function test_empty_metadata_round_trips(): void
    {
        $metadata = new RemarkMetadata;

        $this->assertSame([], $metadata->toArray());
        $this->assertEquals(new RemarkMetadata, RemarkMetadata::fromArray(null));
    }

    public function test_ai_mentions_are_stored_and_retrieved(): void
    {
        $metadata = (new RemarkMetadata)->withAiMentions(['ira']);

        $stored = $metadata->toArray();

        $this->assertSame(['ira'], $stored[RemarkMetadata::KEY_AI_MENTIONS]);

        $restored = RemarkMetadata::fromArray($stored);

        $this->assertSame(['ira'], $restored->aiMentions);
    }

    public function test_origin_metadata_round_trips(): void
    {
        $metadata = (new RemarkMetadata)
            ->withOrigin(\App\Enums\RemarkOrigin::System, 'workspace_assign');

        $stored = $metadata->toArray();

        $this->assertSame('system', $stored[RemarkMetadata::KEY_ORIGIN]);
        $this->assertSame('workspace_assign', $stored[RemarkMetadata::KEY_SYSTEM_SOURCE]);

        $restored = RemarkMetadata::fromArray($stored);

        $this->assertFalse($restored->countsForTeamActivityKpi());
        $this->assertFalse((new RemarkMetadata)->countsForTeamActivityKpi());
        $this->assertTrue(
            (new RemarkMetadata)
                ->withOrigin(\App\Enums\RemarkOrigin::Manual)
                ->countsForTeamActivityKpi()
        );
    }

    public function test_future_keys_are_preserved_but_ignored_by_helpers(): void
    {
        $data = [
            RemarkMetadata::KEY_REMINDER => ['at' => '2026-07-02T09:00:00+05:30'],
            RemarkMetadata::KEY_PINNED => ['pinned_at' => '2026-07-01T12:00:00+05:30'],
            RemarkMetadata::KEY_ATTACHMENTS => [['id' => 'file-1', 'name' => 'photo.jpg']],
        ];

        $metadata = RemarkMetadata::fromArray($data);

        $this->assertSame($data[RemarkMetadata::KEY_REMINDER], $metadata->reminder);
        $this->assertSame($data[RemarkMetadata::KEY_PINNED], $metadata->pinned);
        $this->assertSame($data[RemarkMetadata::KEY_ATTACHMENTS], $metadata->attachments);
        $this->assertSame($data, $metadata->toArray());
    }
}
