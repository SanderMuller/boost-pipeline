<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Walk\Walk;
use SanderMuller\BoostPipeline\Walk\WalkStep;

/**
 * One pipeline execution.
 *
 * The cursor advances in exactly one place — {@see self::resolveCurrentStep()}.
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
     * The tree as it stood after the last resolution, or at the start.
     *
     * It moves forward only where a change is attributable: at the start, and
     * after a step that DECLARED it rewrites code. Holding the original instead
     * would report every run using a formatter as stale.
     */
    private ?string $baseline;

    private bool $unexplainedChange = false;

    private bool $rewroteAfterChecking = false;

    private function __construct(
        public readonly string $id,
        public readonly Walk $walk,
        private readonly StepRunner $runner,
        private readonly ?TreeFingerprint $tree = null,
    ) {
        $this->baseline = $this->tree?->capture();

        if ($walk->isEmpty()) {
            $this->state = RunState::Complete;
        }
    }

    public static function start(Walk $walk, StepRunner $runner, ?string $id = null, ?TreeFingerprint $tree = null): self
    {
        return new self($id ?? 'r-'.substr(bin2hex(random_bytes(4)), 0, 6), $walk, $runner, $tree);
    }

    public function state(): RunState
    {
        return $this->state;
    }

    public function currentStep(): ?WalkStep
    {
        return $this->walk->at($this->cursor);
    }

    public function position(): string
    {
        return sprintf('%d/%d', min($this->cursor + 1, $this->walk->count()), $this->walk->count());
    }

    /**
     * THE chokepoint. Resolves the step at the cursor and advances only if the
     * verdict says to. Nothing else in the codebase may move the cursor.
     */
    public function resolveCurrentStep(): ?Result
    {
        $current = $this->currentStep();

        if (! $current instanceof WalkStep) {
            $this->state = RunState::Complete;

            return null;
        }

        // A skill step is resolved by acknowledgement, never by the server.
        if ($current->step->kind() === StepKind::Skill) {
            $this->state = RunState::Awaiting;

            return null;
        }

        $this->accountForTree();

        $result = $this->runner->run($current->step, $this->id);
        $this->record($result, $current->step);

        return $result;
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

        $this->accountForTree();

        $result = Result::acknowledged($current->step->id(), $summary);
        $this->record($result, $current->step);

        return $result;
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
        if ($this->results === [] || $this->walk->notices !== []) {
            return false;
        }

        // A receipt is only about the code that was there when the step ran.
        if ($this->staleReason() !== null) {
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
     * Why the receipts no longer describe the tree, or null when they still do.
     *
     * Two different events, and the wording separates them because the fix is
     * different: an edit DURING the walk means earlier steps ran against code
     * that has since changed, while an edit after the walk finished means the
     * whole run describes a tree that no longer exists.
     */
    public function staleReason(): ?string
    {
        if ($this->unexplainedChange) {
            return 'The working tree changed during a step that does not declare that it rewrites code, so this run no longer describes the code on disk. Either something edited files mid-run, or a step needs ->mutating(). Open a new run.';
        }

        if ($this->rewroteAfterChecking) {
            return 'A step that rewrites code ran after a check had already passed, so that check describes code this run then changed. Put steps that rewrite code before the ones that check it — the default phase order does. Open a new run.';
        }

        if ($this->treeHasMoved()) {
            return 'The working tree changed after this run resolved, so its verdicts no longer describe the code on disk. Open a new run.';
        }

        return null;
    }

    /**
     * Whether the tree differs from the last resolution — the signal `open_run`
     * uses to decide between resuming this run and starting a fresh one.
     */
    public function treeHasMoved(): bool
    {
        if (! $this->tree instanceof TreeFingerprint || $this->baseline === null) {
            return false;
        }

        $now = $this->tree->capture();

        return $now !== null && $now !== $this->baseline;
    }

    /**
     * Whether any step so far produced a verdict about the code rather than
     * changing it.
     */
    private function hasCheckedAnything(): bool
    {
        return array_any($this->walk->steps, fn (WalkStep $walkStep) => isset($this->results[$walkStep->step->id()]) && ! $walkStep->step->mutates());
    }

    public function acknowledgedCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (Result $result): bool => ! $result->serverRun(),
        ));
    }

    /**
     * Read the tree at a step boundary and decide whether the difference is
     * explained.
     *
     * A declared-mutating step rebaselines the moment it records, so anything
     * left over here is a change nothing accounted for. One reading per boundary
     * is therefore enough — and no guessing from timing, which cannot tell a
     * formatter apart from an edit made while a step was running.
     */
    private function accountForTree(): void
    {
        if (! $this->tree instanceof TreeFingerprint) {
            return;
        }

        $now = $this->tree->capture();

        if ($now === null) {
            return;
        }

        if ($this->baseline !== null && $now !== $this->baseline) {
            $this->unexplainedChange = true;
        }

        $this->baseline = $now;
    }

    private function record(Result $result, Step $step): void
    {
        $this->results[$result->stepId] = $result;

        // Only a step that said it rewrites code gets its changes absorbed.
        if ($step->mutates()) {
            $after = $this->tree?->capture();

            // Absorbing a rewrite does not make an earlier verdict true again: a
            // check that passed before this step measured different code. But a
            // fix-mode step that found nothing to fix changed nothing, and a
            // clean `pint` must not invalidate a run — so this turns on the tree
            // actually having moved, not on the declaration alone.
            $rewrote = $after !== null && $this->baseline !== null && $after !== $this->baseline;

            if ($rewrote && $this->hasCheckedAnything()) {
                $this->rewroteAfterChecking = true;
            }

            $this->baseline = $after ?? $this->baseline;
        }

        if (! $result->verdict->advancesCursor()) {
            $this->state = $result->verdict->isTerminalForRun()
                ? RunState::Halted
                : RunState::Blocked;

            return;
        }

        $this->cursor++;

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
