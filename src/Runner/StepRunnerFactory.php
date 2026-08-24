<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use Closure;
use SanderMuller\BoostPipeline\Contracts\StepRunner;

/**
 * One runner per pipeline, built on demand.
 *
 * A runner carries the pipeline's default timeout, and two pipelines can declare
 * different ones — so the single runner the container used to hold could only
 * ever be right for one of them. Kept as a factory rather than a wider
 * `StepRunner::run()` signature: that method is public API a consumer
 * implements, and changing it has already been a breaking release once.
 */
final class StepRunnerFactory
{
    /** @var array<string, StepRunner> */
    private array $runners = [];

    /** @param Closure(string): StepRunner $build */
    public function __construct(private readonly Closure $build) {}

    public function for(string $pipeline): StepRunner
    {
        return $this->runners[$pipeline] ??= ($this->build)($pipeline);
    }
}
