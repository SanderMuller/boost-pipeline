<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Mcp\StepPayload;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/**
 * `notices` belongs to the walk, so it is known the moment a run opens.
 *
 * It used to be assembled inside the block gated on results, which is the gate
 * for `all_verified` and `stale` — both properties of results. A run whose config
 * dropped a declared step therefore said nothing about it until the first verdict
 * existed, and `open_run` carried a second copy of the assembly to work around
 * that. These tests pin the ownership: one assembly site, keyed on the walk.
 */
final class OrphanPhase implements Phase
{
    public function id(): string
    {
        return 'orphan';
    }

    public function name(): string
    {
        return 'Orphan';
    }
}

/** A walk holding one registered step and one declared into a phase nothing registers. */
function runWithNotices(): Run
{
    return Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint'));
            $steps->in(OrphanPhase::class)->append(Shell::run('true', id: 'dropped'));
        })->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-notices',
    );
}

it('reports a dropped step the moment the run opens, before any result exists', function (): void {
    $run = runWithNotices();

    expect($run->results())
        ->toBeEmpty()
        ->and($run->walk->notices)->not->toBeEmpty();

    $payload = StepPayload::opened($run);

    // The whole point: the payload builder itself carries the notice now, rather
    // than `OpenRun` appending a second copy because the envelope withheld it.
    expect($payload)->toHaveKey('notices')
        ->and($payload['notices'])->toBe($run->walk->notices);
});

it('changes which keys open_run carries in no other way', function (): void {
    // STOP condition 3 of the spec: `notices` moved into the envelope, so its
    // POSITION in the payload shifts. Nothing else may. Compared as a sorted set
    // rather than in order, because order is the one thing expected to change.
    $withNotices = array_keys(StepPayload::opened(runWithNotices()));
    sort($withNotices);

    expect($withNotices)->toBe(['notices', 'position', 'run', 'state', 'step', 'total_steps']);
});

it('reports it on status too, which is where a reader goes to ask', function (): void {
    $payload = StepPayload::status(runWithNotices());

    expect($payload)->toHaveKey('notices');
});

it('reports it to an agent asked to do skill work it cannot finish verifying', function (): void {
    // The case that justifies the change. A skill step first in the walk returns
    // `awaiting` with no results, so the agent used to be handed the work with no
    // way to learn that a declared step was dropped — and a walk with notices can
    // never reach all_verified: true, however well the agent does the work.
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Skill::run('/evaluate', id: 'review'));
            $steps->in(OrphanPhase::class)->append(Shell::run('true', id: 'dropped'));
        })->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-skill-first',
    );

    $run->resolveCurrent();

    expect($run->results())
        ->toBeEmpty();

    $payload = StepPayload::awaiting($run);

    expect($payload)->toHaveKey('notices')
        ->and($run->allVerified())->toBeFalse();
});

it('reports it on a walk that dropped every step it had', function (): void {
    // The degenerate case, and the one where saying nothing is worst: every
    // declared step went into a phase nothing registers, so the walk is empty and
    // goes straight to `complete` with no results. Without the notice the payload
    // is a bare `complete` — indistinguishable from a pipeline that had no work.
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(OrphanPhase::class)->append(Shell::run('true', id: 'dropped'));
        })->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-empty-walk',
    );

    $run->resolveCurrent();

    expect($run->walk->steps)->toBeEmpty()
        ->and($run->results())->toBeEmpty()
        ->and(StepPayload::complete($run))->toHaveKey('notices');
});

it('can emit stale from the open_run payload, which is why that key is declared', function (): void {
    // The evidence for a decision made twice. `stale` is declared on the shared
    // envelope, so `open_run` advertises it — justified only if the payload it
    // builds can actually carry it. RunManager normally discards a stale run and
    // starts a fresh one, so this proves the narrower claim the declaration rests
    // on: `StepPayload::opened()` emits `stale` for a run holding a result whose
    // tree has since moved. RunManager can hand it such a run because it reads the
    // tree to decide, and the payload reads it again.
    //
    // Not driven through the tool: doing that means flipping the digest between two
    // captures inside one call, which couples the test to how many times the tree
    // is read. That would break on refactors that change nothing about this
    // behaviour.
    $tree = new class implements TreeFingerprint
    {
        public string $digest = 'tree-a';

        public function capture(): string
        {
            return $this->digest;
        }
    };

    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint'));
        })->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-stale-open',
        tree: $tree,
    );

    $run->resolveCurrent();

    expect($run->results())->not->toBeEmpty()
        ->and(StepPayload::opened($run))->not->toHaveKey('stale');

    // Something edited the tree after the step measured it.
    $tree->digest = 'tree-b';

    expect(StepPayload::opened($run))->toHaveKey('stale');
});

it('keeps the key absent when the walk raised no notice', function (): void {
    // Absence is meaningful. An empty array would read as "checked, nothing
    // wrong" in a payload where the key's presence is the signal.
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint'));
        })->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-clean',
    );

    expect($run->walk->notices)
        ->toBeEmpty()
        ->and(StepPayload::opened($run))->not->toHaveKey('notices')
        ->and(StepPayload::status($run))->not->toHaveKey('notices');
});
