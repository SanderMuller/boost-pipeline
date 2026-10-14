<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

/**
 * A runner that can start a position and hand back control before it finishes.
 *
 * The stdio MCP transport answers one request at a time. While `next_step` waits
 * on a step, no other tool call is even read, so `status` cannot answer however
 * cheap it is. Starting the steps and returning after a bounded wait is the only
 * way the server stays responsive during a long step.
 *
 * Separate from {@see BatchStepRunner} so a custom runner keeps working without
 * changes: a run without this contract resolves every position in one call, as it
 * always has.
 */
interface BackgroundStepRunner extends StepRunner
{
    /**
     * Start every step and return without waiting for any of them.
     *
     * @param  list<Step>  $steps
     */
    public function begin(array $steps, string $runId): PendingSteps;
}
