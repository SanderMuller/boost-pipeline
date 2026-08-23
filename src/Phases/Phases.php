<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\Refactoring;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;

/**
 * The ordered set of phases. A phase is nothing but a named, ordered group of
 * steps, which is why a custom one costs no extra machinery.
 *
 * The set is open because the package cannot know a project's phase vocabulary.
 * A pipeline of shell checks fits the five defaults. A pipeline that sequences
 * review work does not: its steps are not refactoring or formatting or tests, and
 * six review lenses all reporting phase Agent tell a reader nothing.
 *
 * Low usage is not a reason to close it again. Configuring phases only matters to
 * the pipelines whose steps the defaults do not describe, so counting callers
 * measures which pipelines exist, not whether the seam earns its place.
 */
final class Phases
{
    /**
     * The defaults, in order. A leading Setup phase was designed and dropped:
     * nothing populated it, and an empty reserved phase advertises a capability
     * the package does not have. `prepend(Setup::class)` restores it.
     *
     * @var list<class-string<Phase>>
     */
    public const array DEFAULTS = [
        Refactoring::class,
        Formatting::class,
        StaticAnalysis::class,
        Tests::class,
        Agent::class,
    ];

    /** @var list<class-string<Phase>> */
    private array $phases = self::DEFAULTS;

    /**
     * Ensure a phase sits last. Appending one that is already registered MOVES
     * it to the end rather than duplicating — which is what makes
     * `append(X)->after(Y)` work for an existing phase too.
     *
     * @param  class-string<Phase>  $phase
     */
    public function append(string $phase): PhasePosition
    {
        $this->remove($phase);
        $this->phases[] = $phase;

        return new PhasePosition($this, $phase);
    }

    /** @param class-string<Phase> $phase */
    public function prepend(string $phase): PhasePosition
    {
        $this->remove($phase);
        array_unshift($this->phases, $phase);

        return new PhasePosition($this, $phase);
    }

    /** @param class-string<Phase> $phase */
    public function remove(string $phase): self
    {
        $this->phases = array_values(array_filter(
            $this->phases,
            static fn (string $registered): bool => $registered !== $phase,
        ));

        return $this;
    }

    /**
     * Move an already-registered phase to sit directly after another.
     *
     * @param  class-string<Phase>  $phase
     * @param  class-string<Phase>  $anchor
     */
    public function moveAfter(string $phase, string $anchor): void
    {
        if ($phase === $anchor) {
            throw InvalidPipelineConfigException::selfAnchor($phase);
        }

        if (! in_array($anchor, $this->phases, true)) {
            throw InvalidPipelineConfigException::unknownAnchor($anchor);
        }

        $this->remove($phase);

        $index = (int) array_search($anchor, $this->phases, true);
        array_splice($this->phases, $index + 1, 0, [$phase]);
    }

    /** @return list<class-string<Phase>> */
    public function all(): array
    {
        return $this->phases;
    }
}
