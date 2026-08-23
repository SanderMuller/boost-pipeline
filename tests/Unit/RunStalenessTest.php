<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Steps\Shell;

/** A digest the test moves by hand, so each case says exactly when the tree changed. */
final class SettableFingerprint implements TreeFingerprint
{
    public function __construct(public ?string $value = 'a') {}

    public function capture(): ?string
    {
        return $this->value;
    }
}

final readonly class AlwaysPasses implements StepRunner
{
    public function run(Step $step, string $runId): Result
    {
        return Result::passed($step->id(), 'ok');
    }
}

/** A fix-mode step: it rewrites the tree as its normal job, the way `pint` does. */
final readonly class RewritesTheTree implements StepRunner
{
    public function __construct(private SettableFingerprint $tree) {}

    public function run(Step $step, string $runId): Result
    {
        $this->tree->value = 'rewritten-by-'.$step->id();

        return Result::passed($step->id(), 'ok');
    }
}

function twoStepPipeline(): Pipeline
{
    return Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'first'))
            ->append(Shell::run('true', id: 'second'));
    });
}

it('verifies a run whose tree never moved', function (): void {
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', new SettableFingerprint);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('still verifies a run whose own steps rewrote the tree', function (): void {
    // The case a single fingerprint per step gets wrong. Both steps here change
    // the tree while running, which is what a fix-mode step does — attributing
    // that to the agent would report a false stale on every such run, and a gate
    // that cries stale when nothing is wrong stops being believed.
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new RewritesTheTree($tree), 'r-test', $tree);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('refuses to verify when the tree changed between two steps', function (): void {
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrentStep();

    // Nothing ran in this gap, so the change is the agent's: the first step's
    // verdict now describes code that is no longer on disk.
    $tree->value = 'edited';

    $run->resolveCurrentStep();

    expect($run->staleReason())->toContain('while this run was in progress')
        ->and($run->allVerified())->toBeFalse();
});

it('refuses to verify when the tree changed after the walk finished', function (): void {
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->allVerified())->toBeTrue();

    $tree->value = 'edited-after';

    expect($run->staleReason())->toContain('after this run resolved')
        ->and($run->allVerified())->toBeFalse();
});

it('does not expire anything when the tree cannot be fingerprinted', function (): void {
    // No git, no digest. Behaviour matches what it was before fingerprinting
    // existed, rather than a run that can never be verified.
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', new SettableFingerprint(null));

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('hands back the same run while the tree sits still', function (): void {
    $manager = new RunManager(twoStepPipeline(), new AlwaysPasses, new SettableFingerprint);

    $first = $manager->open();
    $first->resolveCurrentStep();

    expect($manager->open()->id)->toBe($first->id);
});

it('starts a fresh run once the tree has moved, which is what the fix loop needs', function (): void {
    // Run, see a failure, change the code, verify again. Returning the first run
    // here hands back verdicts about code that no longer exists; refusing to open
    // a second one makes the loop impossible without restarting the server.
    $tree = new SettableFingerprint;
    $manager = new RunManager(twoStepPipeline(), new AlwaysPasses, $tree);

    $first = $manager->open();
    $first->resolveCurrentStep();

    $tree->value = 'after-the-fix';

    $second = $manager->open();

    expect($second->id)->not->toBe($first->id)
        ->and($second->results())
        ->toBeEmpty();
});
