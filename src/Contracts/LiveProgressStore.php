<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Run\LiveProgress;

/**
 * Where a run says what it is doing between two resolutions.
 *
 * One record per pipeline, replaced on every position. A position is entered more
 * than once — a blocked position holds the cursor and the next call re-enters it —
 * so a write replaces rather than accumulates. The receipt is where a verdict
 * history belongs.
 */
interface LiveProgressStore
{
    /**
     * Whether the record reached disk.
     *
     * The caller adopts the new token only on success. A silent failure would
     * otherwise leave the previous record on disk while the run believed it had
     * been replaced — and an awaiting record never expires, so it would outlive
     * the run.
     */
    public function write(LiveProgress $progress): bool;

    public function read(): ?LiveProgress;

    /**
     * Delete the record, but only when it is still the one this caller wrote.
     *
     * Compare-and-delete on run id and token. Two servers share a pipeline with no
     * lock, so an unconditional delete would let one server's cleanup remove the
     * record another server had just written, and the page would go blank mid-run.
     */
    public function clear(string $runId, string $token): void;
}
