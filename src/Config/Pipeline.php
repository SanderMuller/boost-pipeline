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

    private ?string $purpose = null;

    private function __construct()
    {
        $this->phases = new Phases;
        $this->steps = new Steps;
    }

    public static function configure(): self
    {
        return new self;
    }

    /**
     * Register, reorder or remove phases.
     *
     * @param  Closure(Phases): void  $callback
     */
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

    /**
     * The one question this pipeline answers.
     *
     * A project declaring several pipelines has to pick one before it can open a
     * run, and the step list is a poor way to make that choice: two pipelines
     * built from the same closures differ in the one or two steps that decide
     * which question they answer, and telling them apart means reading both.
     * This is where a config says it in a sentence instead.
     *
     * Deliberately NOT part of the declaration digest. It says which question the
     * pipeline answers, never what it would run, so rewording it must not expire
     * the receipts on disk.
     */
    public function withPurpose(string $purpose): self
    {
        $purpose = trim($purpose);

        if ($purpose === '') {
            throw InvalidPipelineConfigException::purposeIsBlank();
        }

        $this->purpose = $purpose;

        return $this;
    }

    /** Null when the config declared none, which every config did before this existed. */
    public function purpose(): ?string
    {
        return $this->purpose;
    }

    public function phases(): Phases
    {
        return $this->phases;
    }

    public function steps(): Steps
    {
        return $this->steps;
    }

    /**
     * Flatten to the ordered list the cursor walks.
     *
     * @param  string|null  $selection  Walk only steps carrying this tag, plus every untagged step.
     */
    public function walk(?string $selection = null): Walk
    {
        // The digest describes the whole declaration, so every scope of one
        // pipeline shares it. Computed here because only a Pipeline holds the
        // pipeline-level settings that are part of that declaration.
        return Walk::resolve($this->phases, $this->steps, $selection, PipelineFingerprint::for($this));
    }
}
