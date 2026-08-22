<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Results\Result;

/**
 * Resolves a shell step to a verdict.
 *
 * Declared here rather than with the concrete runner so the MCP layer can be
 * built against the interface without touching the runner's files. Losing that
 * separation makes the two phases collide — see the spec's Phase 3 note.
 */
interface StepRunner
{
    public function run(Step $step): Result;
}
