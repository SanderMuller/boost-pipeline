<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

use Closure;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
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

    private ?float $timeoutSeconds = null;

    private function __construct()
    {
        $this->phases = new Phases;
        $this->steps = new Steps;
    }

    public static function configure(): self
    {
        return new self;
    }

    /** @param Closure(Steps): void $callback */
    public function withSteps(Closure $callback): self
    {
        $callback($this->steps);

        return $this;
    }

    /**
     * The ceiling for any step that does not set its own.
     *
     * Per-step `->timeout()` covers the one step that needs headroom, but a
     * project whose suite is slow everywhere had no way to move the floor except
     * by repeating itself on every step — and the runner's own default was not
     * reachable from configuration at all.
     */
    public function withTimeout(float $seconds): self
    {
        if ($seconds <= 0.0) {
            throw InvalidPipelineConfigException::timeoutNotPositive($seconds);
        }

        $this->timeoutSeconds = $seconds;

        return $this;
    }

    public function timeoutSeconds(): ?float
    {
        return $this->timeoutSeconds;
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
