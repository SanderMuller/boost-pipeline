<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * Steps that occupy one position in the walk and run at the same time.
 *
 * Concurrency costs the agent nothing here, which is the whole reason it is
 * allowed: the agent does not perform a shell step, it calls `next_step` and
 * waits. Three commands running at once is still one thing in front of it. The
 * gain is not only wall clock — a batch reports every failure in one pass, where
 * a sequence reports the first and hides the rest behind a fix and a re-run.
 *
 * Two constraints are enforced here rather than at run time, so a bad batch fails
 * when the config loads and names the offending step:
 *
 * - Shell steps only. A skill step handed over alongside others is the wall of
 *   context the cursor exists to break up, and the server cannot fan work out to
 *   separate agent contexts to avoid that.
 * - No step that rewrites code. Siblings run against a tree that step is
 *   changing, and with no ordering between them there is nothing to attribute the
 *   change to. Every sibling verdict would describe code that no longer exists.
 */
final readonly class StepBatch
{
    /** @param list<Step> $steps */
    public function __construct(public array $steps)
    {
        foreach ($steps as $step) {
            if ($step->kind() !== StepKind::Shell || ! $step instanceof Shell) {
                throw InvalidPipelineConfigException::batchStepMustBeShell($step->id());
            }

            if ($step->mutates()) {
                throw InvalidPipelineConfigException::batchStepCannotMutate($step->id());
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }
}
