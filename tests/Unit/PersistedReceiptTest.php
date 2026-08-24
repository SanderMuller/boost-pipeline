<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;
use SanderMuller\BoostPipeline\Walk\WalkStep;

/** Passes every shell step, except `false` (a finding) and `missing` (did not run). */
final class CommandRunner implements StepRunner
{
    public function run(Step $step, string $runId): Result
    {
        if (! $step instanceof Shell) {
            return Result::passed($step->id(), 'ok');
        }

        return match ($step->command()) {
            'false' => Result::failed($step->id(), 'found problems', exitCode: 1),
            'missing' => Result::error($step->id(), 'command not found'),
            default => Result::passed($step->id(), 'ok'),
        };
    }
}

/**
 * These assert the receipt ON DISK, never the in-session payload.
 *
 * Every other test read `status`, so a green run's receipt was never checked —
 * and it was wrong. The receipt was written before the state transition, so a
 * finished run persisted as `running`, and `all_verified` ends on
 * `state === Complete`: no run could ever write `all_verified: true`, and
 * `pipeline:verify` could never exit 0. A live payload agreeing with itself
 * proves nothing about the file a consumer actually reads.
 */
beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/bp-persisted-'.bin2hex(random_bytes(4)).'/receipt.json';
    $this->store = new JsonReceiptStore($this->path);
});

afterEach(function (): void {
    if (is_file($this->path)) {
        unlink($this->path);
        rmdir(dirname($this->path));
    }
});

/** @param Closure(Steps): void $declare */
function walkAll(ReceiptStore $store, Closure $declare): Run
{
    return Run::start(
        Pipeline::configure()->withSteps($declare)->walk(),
        new CommandRunner,
        'r-persisted',
        receipts: $store,
    );
}

function drain(Run $run): void
{
    while ($run->currentStep() instanceof WalkStep && $run->state() !== RunState::Blocked && $run->state() !== RunState::Halted) {
        if ($run->state() === RunState::Awaiting) {
            $run->acknowledgeCurrentStep('done');

            continue;
        }

        $run->resolveCurrent();
    }
}

it('persists a fully green all-shell run as complete and verified', function (): void {
    $run = walkAll($this->store, function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'analyse'));
    });

    drain($run);

    $receipt = $this->store->read();

    expect($receipt?->state)->toBe('complete')
        ->and($receipt?->allVerified)->toBeTrue()
        ->and($receipt?->verdicts)->toBe(['fmt' => 'passed', 'analyse' => 'passed']);
});

it('persists the state the run actually reached, not the one before the transition', function (): void {
    // `finished in state [running]` is a run that cannot exist, and it was the
    // message a consumer got from the verify command.
    $run = walkAll($this->store, function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('false', id: 'fmt'));
    });

    drain($run);

    expect($this->store->read()?->state)->toBe('blocked');
});

it('persists a halted run as halted, the other half of the early return', function (): void {
    // `settleState()` returns early for both blocked and halted, and only blocked
    // was pinned. The 0.4.0 bug was the persisted state being wrong on a path no
    // test covered, so leaving its sibling uncovered repeats the same mistake.
    $run = walkAll($this->store, function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('missing', id: 'fmt'));
    });

    drain($run);

    $receipt = $this->store->read();

    expect($receipt?->state)->toBe('halted')
        ->and($receipt?->allVerified)->toBeFalse()
        ->and($receipt?->verdicts)->toBe(['fmt' => 'error']);
});

it('persists an acknowledged run as complete but not verified', function (): void {
    // The state has to be right here too, or "not verified" would be true for
    // the wrong reason and hide a regression in the acknowledgement rule.
    $run = walkAll($this->store, function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
    });

    drain($run);

    $receipt = $this->store->read();

    expect($receipt?->state)->toBe('complete')
        ->and($receipt?->allVerified)->toBeFalse()
        ->and($receipt?->verdicts)->toBe(['fmt' => 'passed', 'review' => 'acknowledged']);
});

it('writes the scope into the receipt a scoped run actually leaves behind', function (): void {
    // The join again: a scoped pass that persisted as unscoped would let
    // `pipeline:verify` report the whole tree verified on the strength of a
    // partial run, which is the false green this feature had to avoid.
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt')->tagged('backend'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'js')->tagged('frontend'));
        })->walk('backend'),
        new CommandRunner,
        'r-scoped',
        receipts: $this->store,
        scope: 'backend',
    );

    drain($run);

    $receipt = $this->store->read();

    expect($receipt?->scope)->toBe('backend')
        ->and($receipt?->allVerified)->toBeTrue()
        ->and($receipt?->verdicts)->toBe(['fmt' => 'passed']);
});
