<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Run\HistoryRecord;

/**
 * Where past runs are kept, one file per run.
 *
 * The receipt answers "does the tree on disk have a pass". This answers "what did
 * the recent walks do", which no single overwritten file could.
 *
 * Read the warning in {@see \SanderMuller\BoostPipeline\Run\Receipt} before
 * treating any of it as proof: history makes a local answer richer, never
 * portable and never trusted.
 */
interface RunHistoryStore
{
    public function write(HistoryRecord $record): void;

    public function read(string $runId): ?HistoryRecord;

    /**
     * Every retained run, newest first.
     *
     * @return list<HistoryRecord>
     */
    public function all(?int $limit = null): array;
}
