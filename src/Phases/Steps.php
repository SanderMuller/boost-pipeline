<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;

/**
 * Where steps attach. Three positions and no more: append and prepend within a
 * phase, and between two phases.
 */
final class Steps
{
    /** @var array<class-string<Phase>, StepCollection> */
    private array $inPhase = [];

    /** @var list<array{after: class-string<Phase>, before: class-string<Phase>, step: Step}> */
    private array $transitions = [];

    /** @param class-string<Phase> $phase */
    public function in(string $phase): StepCollection
    {
        return $this->inPhase[$phase] ??= new StepCollection;
    }

    /**
     * A transition step: an ordinary step anchored between two phases rather than
     * inside either. Not a second concept — same Step, different attach position.
     *
     * @param  class-string<Phase>  $after
     * @param  class-string<Phase>  $before
     */
    public function between(string $after, string $before, Step $step): self
    {
        $this->transitions[] = ['after' => $after, 'before' => $before, 'step' => $step];

        return $this;
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
     * Every phase that steps were declared into, registered or not.
     *
     * @return list<class-string<Phase>>
     */
    public function declaredPhases(): array
    {
        return array_keys($this->inPhase);
    }

    /**
     * @return list<array{after: class-string<Phase>, before: class-string<Phase>, step: Step}>
     */
    public function transitions(): array
    {
        return $this->transitions;
    }
}
