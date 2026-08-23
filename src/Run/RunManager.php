<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;

/**
 * Holds the session's single run.
 *
 * State is in-process, so there is at most one run per server process and the
 * server process is per session.
 *
 * open_run is idempotent for a given tree: it returns the run already open rather
 * than discarding its verdicts. It is NOT idempotent across an edit. The fix loop
 * is run, see a failure, change the code, verify again — and returning the old
 * run there would hand back verdicts about code that no longer exists, while
 * refusing to start a second one would make the loop impossible without
 * restarting the server. A changed tree is a different question, so it gets a
 * different run.
 */
final class RunManager
{
    private ?Run $run = null;

    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly StepRunner $runner,
        private readonly ?TreeFingerprint $tree = null,
    ) {}

    public function open(): Run
    {
        if ($this->run instanceof Run && $this->run->treeHasMoved()) {
            $this->run = null;
        }

        return $this->run ??= Run::start($this->pipeline->walk(), $this->runner, tree: $this->tree);
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
