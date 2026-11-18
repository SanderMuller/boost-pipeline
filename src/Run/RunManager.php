<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Config\ConfigReloader;
use SanderMuller\BoostPipeline\Config\InstallState;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\ServerSupervisor;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Runner\PipelineTree;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;

/**
 * Holds one run per pipeline.
 *
 * State is in-process, so these live and die with the server process. A client
 * session has one server process, or several when the supervisor restarts it. A project declaring one pipeline holds one run,
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

    /**
     * The map each open run was started from.
     *
     * @var array<string, Pipelines>
     */
    private array $openedFrom = [];

    public function __construct(
        private Pipelines $pipelines,
        private readonly StepRunnerFactory $runners,
        private readonly ?TreeFingerprint $tree = null,
        private readonly ?ReceiptStoreFactory $receipts = null,
        private readonly ?RunHistoryStoreFactory $history = null,
        private readonly ?LiveProgressStoreFactory $live = null,
        private readonly ?ConfigReloader $config = null,
        private readonly ?InstallState $install = null,
        private readonly ?ServerSupervisor $supervisor = null,
        private readonly bool $requireInstalledLock = true,
    ) {}

    /** @var list<string> */
    private array $warnings = [];

    /** Whether an answer carried the restart notice; set only when open() succeeds, so a refused call does not use it up. */
    private bool $restartAnnounced = false;

    private bool $toolListChanged = false;

    /**
     * @param  string|null  $pipeline  Which pipeline to walk. Required when the project declares several.
     * @param  string|null  $selection  Walk only steps carrying this tag, plus every untagged step.
     *
     * @throws InvalidPipelineConfigException
     */
    public function open(?string $pipeline = null, ?string $selection = null): Run
    {
        $this->warnings = [];
        $this->toolListChanged = false;

        $run = $this->openRun($pipeline, $selection);
        $this->restartAnnounced = $this->restartAnnounced || $this->supervisor?->isRestart() === true;

        return $run;
    }

    /** @throws InvalidPipelineConfigException */
    private function openRun(?string $pipeline, ?string $selection): Run
    {
        $this->guardInstalledPackages();
        $this->reloadChangedConfig();

        $name = $this->resolveName($pipeline);
        $run = $this->runs[$name] ?? null;

        // A different selection asks a different question, so the run already
        // open answers the wrong one. Same reasoning as a tree that moved, and it
        // touches only this pipeline's entry.
        if ($run instanceof Run && $run->scope !== $selection) {
            $run->abandon();
            $run = null;
        }

        // A step still running is not interrupted by a reopen. The tree it
        // measured was read before it started, so an edit made meanwhile already
        // makes its verdict stale once collected; replacing the run here would
        // only throw away a suite that is minutes into its work.
        if ($run instanceof Run && $run->isPending()) {
            return $run;
        }

        // A run walks the declaration it opened with, so a reload replaces it
        // before the tree is even asked about — the rebaseline below would
        // otherwise keep a stale walk for a run that has recorded nothing yet.
        // Not by digest: that leaves out env values and the response window,
        // which the run also carries.
        if ($run instanceof Run && ($this->openedFrom[$name] ?? null) !== $this->pipelines) {
            $run->abandon();
            $run = null;
        }

        if ($run instanceof Run) {
            // One capture answers both. Asking separately reads the tree twice on
            // the call an agent makes most, and lets the two answers describe
            // different trees when it moves in between.
            //
            // A run that is then replaced or rebaselined reads the tree again,
            // and should: that read is the new baseline the run starts from, not
            // a repeat of this question. Threading this digest into it would
            // baseline against a moment already past.
            ['moved' => $moved, 'stale' => $stale] = $run->condition();
            $moved = $moved || $run->installedMoved();

            if ($moved && $run->results() === []) {
                // Nothing recorded, so no verdict to lose: it takes the new tree
                // and keeps its id instead of being thrown away.
                $run->rebaseline();
            } elseif ($moved) {
                $run->abandon();
                $run = null;
            } elseif ($stale) {
                // A stale run can never verify: an earlier step measured a tree
                // that no longer exists, and no later resolution undoes that.
                // Reopening is the documented way out of a fix applied with
                // next_step, so it has to be a real one — and by then the tree has
                // stopped moving, because resolving that step absorbed it.
                $run->abandon();
                $run = null;
            }
        }

        if (! $run instanceof Run) {
            $declared = $this->pipelineNamed($name);

            $run = Run::start(
                $declared->walk($selection),
                $this->runners->for($name),
                tree: PipelineTree::for($this->tree, $declared),
                receipts: $this->receipts?->for($name),
                scope: $selection,
                pipeline: $this->pipelines->requiresName() ? $name : null,
                history: $this->history?->for($name),
                live: $this->live?->for($name),
                responseWindow: $declared->responseWindow(),
                install: $this->install,
            );

            $this->openedFrom[$name] = $this->pipelines;
        }

        return $this->runs[$name] = $run;
    }

    /**
     * Warnings the last open() collected for its answer.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Whether the last open() changed the tool schema the client holds.
     *
     * The `pipeline` argument names the declared pipelines and exists only for a
     * map of them, so a reload that renames a pipeline, or switches between one
     * pipeline and a map, leaves the client with a stale schema. So does a
     * restart, because the new server cannot know what the old one declared.
     * True even when open() then threw: the error answer carries it too.
     */
    public function toolListChanged(): bool
    {
        return $this->toolListChanged;
    }

    /**
     * Refuse, or restart the server, when `vendor/` is not what this server can run against.
     *
     * Two questions, in this order. Does `vendor/` hold what `composer.lock`
     * names? If not, an install is running, failed or never ran, and a run would
     * verify against packages the lock does not name. Then: did a package that
     * autoloads code change since this server started? If so, this process holds
     * classes from the old versions. Under the supervisor it exits and a new
     * server takes the request; otherwise it refuses.
     *
     * A restart drops every run this process holds, so it waits while any step
     * runs. An unfinished walk does not block it: its passes measured the old
     * packages, and the next open_run of its pipeline replaces it anyway.
     *
     * @throws InvalidPipelineConfigException
     */
    private function guardInstalledPackages(): void
    {
        if ($this->supervisor?->isRestart() === true && ! $this->restartAnnounced) {
            $this->toolListChanged = true;
            $this->warnings[] = 'The pipeline server restarted after a vendor/ change. Runs opened before the restart are gone; their receipts and history stay on disk.';
        }

        $differences = $this->install?->differences() ?? [];

        if ($differences !== []) {
            if ($this->requireInstalledLock) {
                throw InvalidPipelineConfigException::installInProgress($differences);
            }

            $this->warnings[] = InvalidPipelineConfigException::installInProgress($differences)->getMessage();
        }

        if ($this->config?->vendorChanged() !== true) {
            return;
        }

        // Even with the lock check switched off: a new server must not boot on a
        // half-installed vendor/, and this one cannot run the new packages. An
        // unreadable installed.json counts as no difference above, so it is
        // checked here too.
        if ($differences === [] && $this->install?->installedReadable() === false) {
            $differences = ['vendor/composer/installed.json is missing or cannot be read'];
        }

        if ($differences !== []) {
            throw InvalidPipelineConfigException::installInProgress($differences);
        }

        $supervised = $this->supervisor?->isSupervised() === true;
        $blocker = $this->restartBlocker();

        if ($supervised && $blocker === null) {
            $this->supervisor?->restart();
        }

        throw InvalidPipelineConfigException::vendorChanged($supervised, $blocker);
    }

    /** Why a restart would lose work in flight, or null when it would not. */
    private function restartBlocker(): ?string
    {
        foreach ($this->runs as $name => $run) {
            if ($run->isPending()) {
                return sprintf('a step of pipeline [%s] is still running. Call next_step until it finishes, then call open_run again', $name);
            }
        }

        return null;
    }

    /**
     * Swap in the config on disk when it changed since it was loaded.
     *
     * Only here, between runs. `next_step` and `status` keep walking the run they
     * have, so a reload never changes a walk under its cursor.
     *
     * @throws InvalidPipelineConfigException when it changed and cannot be reloaded in place
     */
    private function reloadChangedConfig(): void
    {
        $fresh = $this->config?->refresh();

        if ($fresh instanceof Pipelines) {
            if ($fresh->names() !== $this->pipelines->names() || $fresh->requiresName() !== $this->pipelines->requiresName()) {
                $this->toolListChanged = true;
            }

            $this->pipelines = $fresh;
            $this->runners->flush();
        }
    }

    /**
     * A run whose step still runs stays reachable by its name after a reload
     * removed its pipeline, so the agent can collect the verdict and a restart
     * is not blocked by a run nobody can finish.
     *
     * @throws InvalidPipelineConfigException
     */
    public function for(?string $pipeline = null): ?Run
    {
        $orphan = $pipeline === null || $this->pipelines->has($pipeline) ? null : $this->runs[$pipeline] ?? null;

        if ($orphan instanceof Run && $orphan->isPending()) {
            return $orphan;
        }

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
