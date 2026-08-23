<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Walk;

use SanderMuller\BoostPipeline\Contracts\Step;

/**
 * One step in the resolved walk, plus the phase it was found in.
 *
 * Steps sharing a `batchId` occupy the same position and resolve together. null
 * means the step has a position to itself, which is the common case.
 */
final readonly class WalkStep
{
    public function __construct(
        public Step $step,
        public string $phaseId,
        public string $phaseName,
        public ?int $batchId = null,
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
