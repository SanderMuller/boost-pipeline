<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\PendingSteps;
use SanderMuller\BoostPipeline\Contracts\Step;

/**
 * A position whose steps were started and have not all finished.
 *
 * `measuredAt` is the tree from BEFORE the steps started, the same reading a
 * blocking resolution takes. An edit made while a step runs therefore makes the
 * run stale once its verdict is recorded, exactly as an edit made during a
 * blocking call would.
 *
 * @internal
 */
final readonly class PendingPosition
{
    /** @param list<Step> $steps */
    public function __construct(
        public PendingSteps $pending,
        public array $steps,
        public ?string $measuredAt,
        public string $liveToken,
        public float $startedAt,
    ) {}
}
