<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use SanderMuller\BoostPipeline\Contracts\Step;

/**
 * The steps inside one phase. Chainable so a phase reads as a list.
 */
final class StepCollection
{
    /** @var list<Step> */
    private array $steps = [];

    public function append(Step $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    public function prepend(Step $step): self
    {
        array_unshift($this->steps, $step);

        return $this;
    }

    /** @return list<Step> */
    public function all(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }
}
