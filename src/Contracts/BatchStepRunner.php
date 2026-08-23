<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Results\Result;

/**
 * A runner that can resolve several steps at the same time.
 *
 * Separate from {@see StepRunner} so a custom runner keeps working without
 * changes: a run checks for this interface and falls back to resolving a batch
 * one step after another, which is correct, just not concurrent.
 */
interface BatchStepRunner extends StepRunner
{
    /**
     * Resolve every step at once and return a verdict for each.
     *
     * Every step runs to completion. Abandoning the siblings once one fails would
     * throw away the results the batch was declared for — learning every failure
     * in one pass is half the point of grouping them.
     *
     * @param  list<Step>  $steps
     * @return array<string, Result> keyed by step id
     */
    public function runBatch(array $steps, string $runId): array;
}
