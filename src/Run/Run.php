<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\BatchStepRunner;
use SanderMuller\BoostPipeline\Contracts\LiveProgressStore;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\RunHistoryStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;
use SanderMuller\BoostPipeline\Walk\Walk;
use SanderMuller\BoostPipeline\Walk\WalkStep;

/**
 * One pipeline execution.
 *
 * The cursor advances in exactly one place — {@see self::resolveCurrent()}.
 * That is the whole guarantee: the server only ever executes the step at the
 * cursor, and only a resolution moves it. Duplicating this logic per tool would
 * make the guarantee only as strong as its weakest copy.
 *
 * State is in-process and dies with the session (v1).
 */
final class Run
{
    private int $cursor = 0;

    /** @var array<string, Result> */
    private array $results = [];

    private RunState $state = RunState::Open;

    /**
     * The tree each surviving receipt was measured against, by step id.
     *
     * null marks a step exempt from the comparison: a step that rewrites code
     * reports whether the tool ran, not that the tree is in some state, so its
     * own writes must not expire it.
     *
     * Per receipt rather than one flag for the run, because a receipt is replaced
     * when its step is retried. A step fails, you fix it, the retry passes — and
     * nothing is left asserting anything about the old tree. A run-level flag
     * stayed stuck there and called the run stale for having been fixed.
     *
     * A numeric-string id (e.g. '123') coerces to an int key on write, so the
     * key type is array-key, not string.
     *
     * @var array<array-key, string|null>
     */
    private array $measuredAt = [];

    /**
     * Step id => whether its pass asserted the state of the tree.
     *
     * Separate from `measuredAt`, which is null both for a step that asserted
     * nothing and for a run with no fingerprint to measure with. The receipt
     * needs the first without the second.
     *
     * @var array<string, bool>
     */
    private array $asserted = [];

    /** The tree after the last resolution, which is what a fresh run compares to. */
    private ?string $lastSeen;

    /** Owns the live record this run wrote last, so a clear can prove it is ours. */
    private ?string $liveToken = null;

    private function __construct(
        public readonly string $id,
        public readonly Walk $walk,
        private readonly StepRunner $runner,
        private readonly ?TreeFingerprint $tree = null,
        private readonly ?ReceiptStore $receipts = null,
        public readonly ?string $scope = null,
        private readonly ?RunHistoryStore $history = null,
        private readonly ?LiveProgressStore $live = null,
        /**
         * Which pipeline this run walks, when the project declares more than one.
         *
         * A label, read only by the payload envelope. It never reaches the cursor
         * or a verdict: the walk this run holds already IS the pipeline, and a
         * second place to look one up is a second place for the two to disagree.
         *
         * Null for a project declaring one pipeline, so nothing changes in a
         * payload that never had a name to report.
         */
        public readonly ?string $pipeline = null,
    ) {
        $this->lastSeen = $this->tree?->capture();

        if ($walk->isEmpty()) {
            $this->state = RunState::Complete;
        }
    }

    public static function start(
        Walk $walk,
        StepRunner $runner,
        ?string $id = null,
        ?TreeFingerprint $tree = null,
        ?ReceiptStore $receipts = null,
        ?string $scope = null,
        ?string $pipeline = null,
        ?RunHistoryStore $history = null,
        ?LiveProgressStore $live = null,
    ): self {
        return new self(
            $id ?? 'r-'.substr(bin2hex(random_bytes(4)), 0, 6),
            $walk,
            $runner,
            $tree,
            $receipts,
            $scope,
            $history,
            $live,
            $pipeline,
        );
    }

    public function state(): RunState
    {
        return $this->state;
    }

    public function currentStep(): ?WalkStep
    {
        return $this->walk->at($this->cursor);
    }

    /**
     * Every step sharing the position at the cursor, in declaration order.
     *
     * @return list<WalkStep>
     */
    public function currentPosition(): array
    {
        return $this->walk->positionAt($this->cursor);
    }

    /**
     * Where the cursor is, counted in steps.
     *
     * A parallel group reports the range it covers — `2-3/7` — because a single
     * number there reads like a count of remaining handovers and is not one: a
     * seven-step walk holding two groups takes five calls, not seven.
     */
    public function position(): string
    {
        $total = $this->walk->count();
        $first = min($this->cursor + 1, $total);
        $width = count($this->walk->positionAt($this->cursor));

        if ($width <= 1) {
            return sprintf('%d/%d', $first, $total);
        }

        return sprintf('%d-%d/%d', $first, min($this->cursor + $width, $total), $total);
    }

    /**
     * THE chokepoint. Resolves the position at the cursor and advances only if
     * every verdict says to. Nothing else in the codebase may move the cursor.
     *
     * A position is usually one step. A parallel group is several, and resolves as
     * a unit: all of them run, all of their verdicts are recorded, and the cursor
     * moves past the whole group or not at all.
     *
     * @return list<Result> empty when there was nothing to resolve
     */
    public function resolveCurrent(): array
    {
        $position = $this->walk->positionAt($this->cursor);

        if ($position === []) {
            $this->state = RunState::Complete;

            return [];
        }

        // A skill step is resolved by acknowledgement, never by the server. Only
        // a lone step can be one, because a batch refuses them at config time.
        if ($position[0]->step->kind() === StepKind::Skill) {
            $this->state = RunState::Awaiting;
            $this->writeLive(RunState::Awaiting, $position);

            return [];
        }

        $measuredAt = $this->tree?->capture();
        $steps = array_map(static fn (WalkStep $walkStep): Step => $walkStep->step, $position);
        $token = $this->writeLive(RunState::Running, $position);

        try {
            $results = $this->record($this->resolveSteps($steps), $steps, $measuredAt);

            // The ordinary handover: a shell step whose successor is a skill step
            // settles to Awaiting inside record(), never through the branch above.
            if ($this->state === RunState::Awaiting) {
                $this->writeLive(RunState::Awaiting, $this->walk->positionAt($this->cursor));
            }

            return $results;
        } finally {
            // Unconditional by design: the handover above wrote a newer token, so
            // this compare-and-delete is a no-op there. That is what lets one
            // finally cover the ordinary exit and a throwing runner alike.
            $this->clearLive($token);
        }
    }

    /**
     * @param  list<Step>  $steps
     * @return array<string, Result>
     */
    private function resolveSteps(array $steps): array
    {
        if (count($steps) === 1) {
            $step = $steps[0];

            return [$step->id() => $this->runner->run($step, $this->id)];
        }

        // A custom runner that does not implement the batch contract still works;
        // its group resolves one step after another, which is correct and slower.
        if ($this->runner instanceof BatchStepRunner) {
            return $this->runner->runBatch($steps, $this->id);
        }

        $results = [];

        foreach ($steps as $step) {
            $results[$step->id()] = $this->runner->run($step, $this->id);
        }

        return $results;
    }

    /**
     * Accept the agent's acknowledgement for the skill step under the cursor.
     * Valid only while awaiting — a shell step is the server's to resolve.
     */
    public function acknowledgeCurrentStep(string $summary): Result
    {
        $current = $this->currentStep();

        if (! $current instanceof WalkStep || $current->step->kind() !== StepKind::Skill) {
            throw AcknowledgementNotAllowed::forState($this->state);
        }

        // The agent did its work before calling this, so what is on disk now is
        // what the skill measured — or produced, where it declared as much.
        $measuredAt = $this->tree?->capture();

        // Captured before the try: writeLive() below replaces liveToken, and a
        // finally reading the field would then delete the record it just wrote.
        $token = $this->liveToken;

        try {
            $result = $this->proveOrAcknowledge($current->step, $summary);
            $this->record([$result->stepId => $result], [$current->step], $measuredAt);

            if ($this->state === RunState::Awaiting) {
                $this->writeLive(RunState::Awaiting, $this->walk->positionAt($this->cursor));
            }

            return $result;
        } finally {
            // The only boundary that ends an awaiting state, so the only one that
            // can clear its record — including when a declared proof throws.
            $this->clearLive($token);
        }
    }

    /**
     * Adopt the tree as it stands, valid only while nothing has been recorded.
     *
     * A run with no receipts has nothing to invalidate, so an edit before its
     * first step is not a reason to replace it — doing that churned through run
     * ids while the agent was still deciding what to run.
     */
    public function rebaseline(): void
    {
        if ($this->results !== []) {
            return;
        }

        $this->lastSeen = $this->tree?->capture();
    }

    /**
     * Check a declared proof, or fall back to taking the agent's word.
     *
     * The proof runs through the same runner as any shell step, so it inherits
     * the logging, output bounding and timeout — and, more to the point, the
     * server owns the verdict. A step with a proof therefore reports `passed`
     * rather than `acknowledged`: it is the one path by which agent work becomes
     * something the run actually verified.
     */
    private function proveOrAcknowledge(Step $step, string $summary): Result
    {
        $proof = $step instanceof Skill ? $step->proof() : null;

        if ($proof === null) {
            return Result::acknowledged($step->id(), $summary);
        }

        $result = $this->runner->run(Shell::run($proof, id: $step->id()), $this->id);

        // A failing proof holds the cursor, so the agent is handed the same step
        // again — claiming done without the artifact must not be a way past it.
        // It has to say which command failed: a silent proof such as `grep -q`
        // produced "Failed with no output", which reads like the skill itself
        // failed and names nothing the agent can act on.
        if ($result->verdict !== Verdict::Passed) {
            return new Result(
                verdict: $result->verdict,
                stepId: $result->stepId,
                summary: sprintf('Proof did not hold for [%s]: `%s`. %s', $step->id(), $proof, $result->summary),
                exitCode: $result->exitCode,
                logPath: $result->logPath,
                filesInspected: $result->filesInspected,
                reason: $result->reason,
            );
        }

        return Result::passed(
            $step->id(),
            sprintf('%s — proof passed: %s', $summary, $result->summary),
            logPath: $result->logPath,
        );
    }

    /**
     * How many steps this run's scope left out of the walk.
     *
     * Counted while the walk resolves, so it needs no second resolution to
     * compare against. A reader can tell a small pipeline from a narrowed one.
     */
    public function excludedByScope(): int
    {
        if ($this->scope === null) {
            return 0;
        }

        return $this->walk->excluded;
    }

    /** @return array<string, Result> */
    public function results(): array
    {
        return $this->results;
    }

    public function resultFor(string $stepId): ?Result
    {
        return $this->results[$stepId] ?? null;
    }

    /**
     * True only when every resolved step was a verdict the server verified AND no
     * declared step was dropped before the walk began.
     *
     * `state === Complete` deliberately does NOT imply this: a run of nothing but
     * acknowledgements reaches Complete. Any consumer keying on the state alone
     * would count unverified work as success, so this is reported alongside it.
     *
     * The notices clause is the subtler half. A step declared into a phase nothing
     * registered is dropped with a notice, and so is a tag selection no step
     * carries — so every REMAINING step can pass and the run would otherwise claim
     * full verification while a step the config declared never ran at all.
     */
    public function allVerified(): bool
    {
        return $this->verifiedGiven($this->staleReason());
    }

    /**
     * Both answers from ONE reading of the tree.
     *
     * Asking separately let the tree move between them, so a single response
     * could carry `all_verified: true` beside a `stale` message — the two fields
     * contradicting each other in the same payload, which is worse than either
     * being wrong on its own.
     *
     * @return array{all_verified: bool, stale: string|null}
     */
    public function verification(): array
    {
        $stale = $this->staleGiven($this->tree?->capture());

        return ['all_verified' => $this->verifiedGiven($stale), 'stale' => $stale];
    }

    private function verifiedGiven(?string $stale): bool
    {
        if ($this->results === [] || $this->walk->notices !== []) {
            return false;
        }

        if ($stale !== null) {
            return false;
        }

        foreach ($this->results as $result) {
            if (! $result->verdict->isVerified()) {
                return false;
            }
        }

        return $this->state === RunState::Complete;
    }

    /**
     * Counts only verdicts the server produced itself.
     *
     * The match is exhaustive on purpose rather than writing
     * `$tally[$result->verdict->value]`: a dynamic key would let a future verdict
     * (Acknowledged today) silently open a fourth bucket in a tally whose whole
     * point is that it reports server-produced results only.
     *
     * @return array{passed: int, failed: int, error: int}
     */
    public function serverRunTally(): array
    {
        $tally = ['passed' => 0, 'failed' => 0, 'error' => 0];

        foreach ($this->results as $result) {
            match ($result->verdict) {
                Verdict::Passed => $tally['passed']++,
                Verdict::Failed => $tally['failed']++,
                Verdict::Error => $tally['error']++,
                Verdict::Acknowledged => null,
            };
        }

        return $tally;
    }

    /**
     * Why a receipt no longer describes the tree, or null when they all still do.
     */
    public function staleReason(): ?string
    {
        return $this->staleGiven($this->tree?->capture());
    }

    /**
     * Whether this run can still be reused, read from ONE fingerprint capture.
     *
     * `treeHasMoved()` and `staleReason()` each capture, so asking both costs two
     * `git status` runs plus a hash of every dirty file — on the call an agent
     * makes most. Worse, the tree can move between the two readings, and then the
     * answers describe different trees.
     *
     * @return array{moved: bool, stale: bool}
     */
    public function condition(): array
    {
        $now = $this->tree?->capture();

        return [
            'moved' => $now !== null && $this->lastSeen !== null && $now !== $this->lastSeen,
            'stale' => $this->staleGiven($now) !== null,
        ];
    }

    private function staleGiven(?string $now): ?string
    {
        if ($now === null) {
            return null;
        }

        foreach ($this->measuredAt as $stepId => $measuredAt) {
            // A step id of "123" arrives as an int, because PHP coerces
            // numeric-string array keys. Cast it back rather than crash on a legal id.
            $stepId = (string) $stepId;

            if ($measuredAt !== null && $measuredAt !== $now) {
                return sprintf(
                    'Step [%s] measured a different working tree than the one on disk now, so its verdict is not proven for this code. Something edited files, or the commit moved (a commit, amend, checkout or rebase — the fingerprint covers HEAD too, so this needs no file to change and nothing to undo), or a step that rewrites code is missing ->mutating(), which belongs before the checks that must see it.%s Open a new run.',
                    $stepId,
                    // Naming a step in a group would read as identifying the writer.
                    // Every step in a group measures the same tree from before the
                    // group ran, so the one named is simply the first that passed.
                    $this->walk->isGrouped($stepId)
                        ? ' That step ran in a parallel group, so it is the first of the group that passed rather than the one identified as writing: the group shares a single measurement and cannot tell its members apart.'
                        : '',
                );
            }
        }

        return null;
    }

    /**
     * Whether the tree differs from the last resolution — the signal `open_run`
     * uses to choose between resuming this run and starting a fresh one.
     */
    public function treeHasMoved(): bool
    {
        if (! $this->tree instanceof TreeFingerprint || $this->lastSeen === null) {
            return false;
        }

        $now = $this->tree->capture();

        return $now !== null && $now !== $this->lastSeen;
    }

    /**
     * Write what this position is doing, and return the token that owns it.
     *
     * A blocked position holds the cursor and is entered again, so this replaces
     * rather than accumulates. The fresh token per entry is what lets a later
     * clear tell its own record from one written since.
     *
     * The state is passed, not read: a position that is still executing has not
     * transitioned yet — the first one still reads `open`.
     *
     * @param  list<WalkStep>  $position
     */
    private function writeLive(RunState $state, array $position): string
    {
        $token = bin2hex(random_bytes(8));

        if (! $this->live instanceof LiveProgressStore || $position === []) {
            $this->liveToken = $token;

            return $token;
        }

        $written = $this->live->write(new LiveProgress(
            runId: $this->id,
            token: $token,
            state: $state,
            stepIds: array_map(static fn (WalkStep $walkStep): string => $walkStep->step->id(), $position),
            startedAt: gmdate('c'),
            scope: $this->scope,
            // Only the shipped runner enforces a ceiling, so only it can name one.
            // A custom runner records none, and its record then never expires on
            // age — an honest absence rather than this runner's default applied to
            // a runner that does not use it.
            timeoutSeconds: $this->runner instanceof ProcessStepRunner
                ? $this->runner->effectiveTimeout($position[0]->step)
                : null,
        ));

        // Adopted only on success. A failed replacement leaves the previous
        // record on disk, and clearing the token that never landed would strand
        // it — an awaiting record never expires on age, so it would outlive the
        // run entirely.
        if ($written) {
            $this->liveToken = $token;
        }

        return $this->liveToken ?? $token;
    }

    private function clearLive(?string $token): void
    {
        if ($token !== null) {
            $this->live?->clear($this->id, $token);
        }
    }

    /**
     * Drop this run's in-flight record because the run itself is being discarded.
     *
     * `RunManager` replaces a run whose scope changed, whose tree moved, or that
     * went stale. An awaiting record never expires on age, so without this an
     * abandoned one would describe a run nobody can reach for as long as the
     * server lives.
     */
    public function releaseLive(): void
    {
        $this->clearLive($this->liveToken);
    }

    /**
     * Written after every resolution, not only at the end: a walk abandoned
     * midway still leaves a readable answer, and that answer is "not verified".
     */
    private function recordReceipt(): void
    {
        if (! $this->receipts instanceof ReceiptStore && ! $this->history instanceof RunHistoryStore) {
            return;
        }

        $verification = $this->verification();

        $receipt = new Receipt(
            runId: $this->id,
            state: $this->state->value,
            allVerified: $verification['all_verified'],
            tree: $this->lastSeen,
            stale: $verification['stale'],
            verdicts: array_map(
                static fn (Result $result): string => $result->verdict->value,
                $this->results,
            ),
            recordedAt: gmdate('c'),
            scope: $this->scope,
            // Records that coverage broke, never which notice broke it. A reader
            // needing that reads `status` on the live run; copying the text here
            // would make the receipt a log and invite a consumer to parse it.
            coverage: $this->walk->notices === [] ? 'complete' : 'incomplete',
            asserted: array_keys(array_filter($this->asserted)),
        );

        $this->receipts?->write($receipt);

        // History keeps what the receipt discards: where each step's output went.
        // A consumer may bind its own runner and write logs anywhere, or nowhere,
        // so the path a step actually produced is the only one worth recording.
        $this->history?->write(new HistoryRecord($receipt, array_map(
            static fn (Result $result): ?string => $result->logPath,
            $this->results,
        )));
    }

    public function acknowledgedCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (Result $result): bool => ! $result->serverRun(),
        ));
    }

    /**
     * @param  array<string, Result>  $results
     * @param  list<Step>  $steps
     * @return list<Result>
     */
    private function record(array $results, array $steps, ?string $measuredAt): array
    {
        foreach ($steps as $step) {
            $result = $results[$step->id()];
            $this->results[$step->id()] = $result;

            // Only a pass asserts something about the tree. An acknowledgement was
            // never verified, and a failure or error is already keeping the run from
            // being green — stamping those just produces a stale message about a
            // receipt that claims nothing.
            $assertsTreeState = $result->verdict->isVerified() && ! $step->mutates();

            $this->measuredAt[$step->id()] = $assertsTreeState ? $measuredAt : null;
            $this->asserted[$step->id()] = $assertsTreeState;
        }

        $this->lastSeen = $this->tree?->capture() ?? $measuredAt ?? $this->lastSeen;

        $this->settleState(array_values($results), count($steps));

        // Written last, once the state is final. Persisting it before the
        // transition recorded a finished run as still running, and
        // `all_verified` ends on `state === Complete` — so every green run was
        // written to disk as unverified and `pipeline:verify` could never exit 0.
        $this->recordReceipt();

        return array_values($results);
    }

    /**
     * The position advances only if every verdict in it does.
     *
     * A group holds if any one of its steps failed, and holds at the group's first
     * step so the next call re-runs the whole group. Re-running a passing sibling
     * costs time and keeps the rule simple: a position either resolved or it did
     * not.
     *
     * @param  list<Result>  $results
     */
    private function settleState(array $results, int $width): void
    {
        $blocking = null;

        foreach ($results as $result) {
            if ($result->verdict->advancesCursor()) {
                continue;
            }

            // An error outranks a failure: a tool that never ran is the more
            // urgent thing to report, and it decides the state for the position.
            if ($blocking === null || $result->verdict->isTerminalForRun()) {
                $blocking = $result;
            }
        }

        if ($blocking instanceof Result) {
            $this->state = $blocking->verdict->isTerminalForRun()
                ? RunState::Halted
                : RunState::Blocked;

            return;
        }

        $this->cursor += $width;

        $next = $this->currentStep();

        // Awaiting on arrival, not on the next call: otherwise the agent is handed
        // a skill step with state "running" and must call next_step again purely
        // to flip the state and be handed the same step twice.
        $this->state = match (true) {
            ! $next instanceof WalkStep => RunState::Complete,
            $next->step->kind() === StepKind::Skill => RunState::Awaiting,
            default => RunState::Running,
        };
    }
}
