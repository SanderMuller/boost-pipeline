<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\Refactoring;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;

/**
 * The ordered set of phases: a fixed skeleton the config fills with steps.
 *
 * Reordering and registration were both configurable and neither was ever used —
 * a phase is only a named, ordered group, so a step that needed a different
 * grouping went into an existing phase instead.
 */
final class Phases
{
    /**
     * The phases, in order. A leading Setup phase was designed and dropped:
     * nothing populated it, and an empty reserved phase advertises a capability
     * the package does not have.
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

    /** @return list<class-string<Phase>> */
    public function all(): array
    {
        return $this->phases;
    }
}
