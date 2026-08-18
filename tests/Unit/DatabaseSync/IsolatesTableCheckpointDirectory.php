<?php

namespace Tests\Unit\DatabaseSync;

trait IsolatesTableCheckpointDirectory
{
    private string $tableCheckpointDirectory;

    protected function isolateTableCheckpointDirectory(): void
    {
        $this->tableCheckpointDirectory = storage_path('framework/testing/checkpoints-'.uniqid('', true));
        config(['database-sync.table_checkpoint_directory' => $this->tableCheckpointDirectory]);
    }

    protected function cleanupTableCheckpointDirectory(): void
    {
        if (! isset($this->tableCheckpointDirectory) || ! is_dir($this->tableCheckpointDirectory)) {
            return;
        }

        array_map('unlink', glob($this->tableCheckpointDirectory.'/*') ?: []);
        rmdir($this->tableCheckpointDirectory);
    }
}
