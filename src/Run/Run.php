<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\StepRunner;
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

    private function __construct(
        public readonly string $id,
        public readonly Walk $walk,
        private readonly StepRunner $runner,
    ) {
        if ($walk->isEmpty()) {
            $this->state = RunState::Complete;
        }
    }

    public static function start(Walk $walk, StepRunner $runner, ?string $id = null): self
    {
        return new self($id ?? 'r-'.substr(bin2hex(random_bytes(4)), 0, 6), $walk, $runner);
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

        $result = $this->runner->run($current->step);
        $this->record($result);

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

        $result = Result::acknowledged($current->step->id(), $summary);
        $this->record($result);

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

    public function acknowledgedCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (Result $result): bool => ! $result->serverRun(),
        ));
    }

    private function record(Result $result): void
    {
        $this->results[$result->stepId] = $result;

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
