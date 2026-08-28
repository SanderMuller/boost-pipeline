<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;

/**
 * Holds one run per pipeline.
 *
 * State is in-process, so these live and die with the server process, and the
 * server process is per session. A project declaring one pipeline holds one run,
 * exactly as before.
 *
 * Several at once is deliberate. An agent that hits a blocking failure in one
 * pipeline can work in another without losing its place, and re-walking a
 * nine-step release pipeline to get back to where it was is the cost of the
 * alternative. What it buys has a price: no run is "the" run any more, so every
 * tool that acts on one has to say which it means.
 *
 * open_run is idempotent for a given pipeline and tree: it returns the run
 * already open rather than discarding its verdicts. It is NOT idempotent across
 * an edit. The fix loop is run, see a failure, change the code, verify again —
 * and returning the old run there would hand back verdicts about code that no
 * longer exists, while refusing to start a second one would make the loop
 * impossible without restarting the server. A changed tree is a different
 * question, so it gets a different run.
 *
 * That last rule is also why switching away and back is not an unconditional
 * resume: a run measured before an edit cannot describe the tree after one.
 */
final class RunManager
{
    /** @var array<string, Run> */
    private array $runs = [];

    public function __construct(
        private readonly Pipelines $pipelines,
        private readonly StepRunnerFactory $runners,
        private readonly ?TreeFingerprint $tree = null,
        private readonly ?ReceiptStoreFactory $receipts = null,
    ) {}

    /**
     * @param  string|null  $pipeline  Which pipeline to walk. Required when the project declares several.
     * @param  string|null  $selection  Walk only steps carrying this tag, plus every untagged step.
     *
     * @throws InvalidPipelineConfigException
     */
    public function open(?string $pipeline = null, ?string $selection = null): Run
    {
        $name = $this->resolveName($pipeline);
        $run = $this->runs[$name] ?? null;

        // A different selection asks a different question, so the run already
        // open answers the wrong one. Same reasoning as a tree that moved, and it
        // touches only this pipeline's entry.
        if ($run instanceof Run && $run->scope !== $selection) {
            $run = null;
        }

        if ($run instanceof Run && $run->treeHasMoved()) {
            // A run that has recorded nothing has no verdict to lose, so it takes
            // the new tree and keeps its id instead of being thrown away.
            if ($run->results() === []) {
                $run->rebaseline();
            } else {
                $run = null;
            }
        }

        // A stale run can never verify: an earlier step measured a tree that no
        // longer exists, and no later resolution undoes that. Reopening is the
        // documented way out of a fix applied with next_step, so it has to be a
        // real one — and by then `treeHasMoved()` says no, because resolving that
        // step absorbed the new tree as the last one seen. Without this the
        // obvious recovery silently returns the same unverifiable run.
        if ($run instanceof Run && $run->staleReason() !== null) {
            $run = null;
        }

        if (! $run instanceof Run) {
            $run = Run::start(
                $this->pipelineNamed($name)->walk($selection),
                $this->runners->for($name),
                tree: $this->tree,
                receipts: $this->receipts?->for($name),
                scope: $selection,
                pipeline: $this->pipelines->requiresName() ? $name : null,
            );
        }

        return $this->runs[$name] = $run;
    }

    /** @throws InvalidPipelineConfigException */
    public function for(?string $pipeline = null): ?Run
    {
        return $this->runs[$this->resolveName($pipeline)] ?? null;
    }

    /**
     * The name a caller meant, or an error saying why there is no answer.
     *
     * Never a default when the project declares several. An agent that omitted
     * the name and got the most recently opened run would advance the wrong
     * cursor, execute the wrong steps and write a verdict into the wrong
     * receipt — silently, which is worse than any error message.
     *
     * @throws InvalidPipelineConfigException
     */
    private function resolveName(?string $pipeline): string
    {
        if ($pipeline === null) {
            return $this->pipelines->implied()
                ?? throw InvalidPipelineConfigException::pipelineNotSelected($this->pipelines->names());
        }

        if (! $this->pipelines->has($pipeline)) {
            throw InvalidPipelineConfigException::unknownPipeline($pipeline, $this->pipelines->names());
        }

        return $pipeline;
    }

    private function pipelineNamed(string $name): Pipeline
    {
        return $this->pipelines->get($name)
            ?? throw InvalidPipelineConfigException::unknownPipeline($name, $this->pipelines->names());
    }
}
