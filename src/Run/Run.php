<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\BatchStepRunner;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Results\Result;
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
     * @var array<string, string|null>
     */
    private array $measuredAt = [];

    /** The tree after the last resolution, which is what a fresh run compares to. */
    private ?string $lastSeen;

    private function __construct(
        public readonly string $id,
        public readonly Walk $walk,
        private readonly StepRunner $runner,
        private readonly ?TreeFingerprint $tree = null,
        private readonly ?ReceiptStore $receipts = null,
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
    ): self {
        return new self($id ?? 'r-'.substr(bin2hex(random_bytes(4)), 0, 6), $walk, $runner, $tree, $receipts);
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

    public function position(): string
    {
        return sprintf('%d/%d', min($this->cursor + 1, $this->walk->count()), $this->walk->count());
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

            return [];
        }

        $measuredAt = $this->tree?->capture();
        $steps = array_map(static fn (WalkStep $walkStep): Step => $walkStep->step, $position);

        return $this->record($this->resolveSteps($steps), $steps, $measuredAt);
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

        $result = $this->proveOrAcknowledge($current->step, $summary);
        $this->record([$result->stepId => $result], [$current->step], $measuredAt);

        return $result;
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
     * The notices clause is the subtler half. A step declared into an unregistered
     * phase, or a transition whose anchors are not adjacent, is dropped with a
     * notice — so every REMAINING step can pass and the run would otherwise claim
     * full verification while a gate the config declared never ran at all.
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

    private function staleGiven(?string $now): ?string
    {
        if ($now === null) {
            return null;
        }

        foreach ($this->measuredAt as $stepId => $measuredAt) {
            if ($measuredAt !== null && $measuredAt !== $now) {
                return sprintf(
                    'Step [%s] measured a different working tree than the one on disk now, so its verdict is not proven for this code. Either something edited files, or a step that rewrites code is missing ->mutating() — and a rewrite belongs before the checks that must see it. Open a new run.',
                    $stepId,
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
     * Written after every resolution, not only at the end: a walk abandoned
     * midway still leaves a readable answer, and that answer is "not verified".
     */
    private function recordReceipt(): void
    {
        if (! $this->receipts instanceof ReceiptStore) {
            return;
        }

        $verification = $this->verification();

        $this->receipts->write(new Receipt(
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
        ));
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
