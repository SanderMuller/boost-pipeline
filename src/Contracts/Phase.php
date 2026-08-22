<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

/**
 * Implementations must be constructible with no arguments — the walk resolver
 * instantiates them directly.
 */
interface Phase
{
    /** Stable identifier, used in responses and log filenames. */
    public function id(): string;

    /** Human-readable name shown to the agent. */
    public function name(): string;
}
