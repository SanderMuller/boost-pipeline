<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

/**
 * Pending steps that can be held back until a machine-wide slot is free.
 *
 * Separate from {@see PendingSteps} so that a runner of your own implements it
 * only if it has slots to wait for.
 */
interface WaitsForSlots
{
    /**
     * The steps not started yet because their slot is taken.
     *
     * @return array<string, string> step id => slot name
     */
    public function waitingForSlots(): array;
}
