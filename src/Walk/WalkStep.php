<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Walk;

use SanderMuller\BoostPipeline\Contracts\Step;

/**
 * One position in the resolved walk: a step plus the phase it was found in.
 */
final readonly class WalkStep
{
    public function __construct(
        public Step $step,
        public string $phaseId,
        public string $phaseName,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->step->id(),
            'phase' => $this->phaseName,
            'kind' => $this->step->kind()->value,
            'description' => $this->step->description(),
        ];
    }
}
