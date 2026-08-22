<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

use Closure;
use SanderMuller\BoostPipeline\Phases\Phases;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Walk\Walk;

/**
 * The `.config/pipeline.php` entry point.
 *
 * Laravel-middleware vocabulary, but flat lists rather than an onion: the walk
 * suspends at each step and resumes on the next tool call, so an onion would
 * force replaying completed steps and make every step carry an idempotency
 * requirement.
 */
final class Pipeline
{
    private readonly Phases $phases;

    private readonly Steps $steps;

    private function __construct()
    {
        $this->phases = new Phases;
        $this->steps = new Steps;
    }

    public static function configure(): self
    {
        return new self;
    }

    /** @param Closure(Phases): void $callback */
    public function withPhases(Closure $callback): self
    {
        $callback($this->phases);

        return $this;
    }

    /** @param Closure(Steps): void $callback */
    public function withSteps(Closure $callback): self
    {
        $callback($this->steps);

        return $this;
    }

    public function phases(): Phases
    {
        return $this->phases;
    }

    public function steps(): Steps
    {
        return $this->steps;
    }

    /** Flatten to the ordered list the cursor walks. */
    public function walk(): Walk
    {
        return Walk::resolve($this->phases, $this->steps);
    }
}
