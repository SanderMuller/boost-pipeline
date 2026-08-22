<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\StepRunner;

/**
 * Holds the session's single run.
 *
 * State is in-process, so there is at most one run per server process and the
 * server process is per session. open_run is therefore idempotent: it opens a
 * run or returns the one already open, and never starts a second — restarting
 * would discard verdicts silently.
 */
final class RunManager
{
    private ?Run $run = null;

    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly StepRunner $runner,
    ) {}

    public function open(): Run
    {
        return $this->run ??= Run::start($this->pipeline->walk(), $this->runner);
    }

    public function current(): ?Run
    {
        return $this->run;
    }

    public function isOpen(): bool
    {
        return $this->run instanceof Run;
    }
}
