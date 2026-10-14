<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Results\Result;

/**
 * Steps a {@see BackgroundStepRunner} started and has not settled yet.
 *
 * The verdicts are the same verdicts a blocking call would produce. Only the
 * moment the caller collects them changes.
 */
interface PendingSteps
{
    /**
     * Wait at most this long, then return every verdict, or null while any step
     * still runs. A call after null continues the same wait: nothing restarts.
     *
     * @return array<string, Result>|null keyed by step id, in the order the steps were given
     */
    public function await(float $seconds): ?array;

    /** Kill whatever is still running. Its verdicts are never collected. */
    public function stop(): void;
}
