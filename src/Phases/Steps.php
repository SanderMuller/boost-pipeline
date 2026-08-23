<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;

/**
 * Where steps attach: append or prepend within a phase.
 */
final class Steps
{
    /** @var array<class-string<Phase>, StepCollection> */
    private array $inPhase = [];

    /** @param class-string<Phase> $phase */
    public function in(string $phase): StepCollection
    {
        return $this->inPhase[$phase] ??= new StepCollection;
    }

    /**
     * @param  class-string<Phase>  $phase
     * @return list<Step>
     */
    public function forPhase(string $phase): array
    {
        return isset($this->inPhase[$phase]) ? $this->inPhase[$phase]->all() : [];
    }

    /**
     * The phase's steps with parallel groups still grouped, for building the walk.
     *
     * @param  class-string<Phase>  $phase
     * @return list<Step|StepBatch>
     */
    public function entriesForPhase(string $phase): array
    {
        return isset($this->inPhase[$phase]) ? $this->inPhase[$phase]->entries() : [];
    }

    /**
     * Every phase that steps were declared into, registered or not.
     *
     * @return list<class-string<Phase>>
     */
    public function declaredPhases(): array
    {
        return array_keys($this->inPhase);
    }
}
