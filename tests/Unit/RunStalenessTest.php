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

/** Rewrites the tree exactly where the step declared that it would. */
final readonly class RewritesWhenDeclared implements StepRunner
{
    public function __construct(private SettableFingerprint $tree) {}

    public function run(Step $step, string $runId): Result
    {
        if ($step->mutates()) {
            $this->tree->value = 'rewritten-by-'.$step->id();
        }

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

/** The same walk, with both steps declaring that they rewrite code. */
function declaredMutatingPipeline(): Pipeline
{
    return Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'first')->mutating())
            ->append(Shell::run('true', id: 'second')->mutating());
    });
}

it('verifies a run whose tree never moved', function (): void {
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', new SettableFingerprint);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('still verifies a run whose steps declared that they rewrite code', function (): void {
    // `pint` and `rector process` change the tree as their normal job. Counting
    // that against the run would report stale on every clean run using one, and a
    // gate that cries stale when nothing is wrong stops being read.
    $tree = new SettableFingerprint;
    $run = Run::start(declaredMutatingPipeline()->walk(), new RewritesTheTree($tree), 'r-test', $tree);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('refuses to verify when a read-only step is the one that changed the tree', function (): void {
    // The case timing cannot catch. Attributing a change to "whatever step was
    // running" absorbs an edit made DURING a step — and a blocked run is exactly
    // when files get edited, against a step that can take half a minute. Either
    // the step rewrites code and must say so, or something edited mid-run; both
    // mean the verdict is not proven for the code that now exists.
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new RewritesTheTree($tree), 'r-test', $tree);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toContain('does not declare that it rewrites code')
        ->and($run->allVerified())->toBeFalse();
});

it('refuses to verify when the tree changed between two steps', function (): void {
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrentStep();

    // Nothing ran in this gap, so the change is the agent's: the first step's
    // verdict now describes code that is no longer on disk.
    $tree->value = 'edited';

    $run->resolveCurrentStep();

    expect($run->staleReason())->toContain('does not declare that it rewrites code')
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

it('refuses to verify when a rewriting step runs after a check already passed', function (): void {
    // The false green the ordering advice only asked for politely. The first step
    // checks and passes against one tree; the second rewrites it. Absorbing the
    // rewrite leaves the run looking current while the first verdict describes
    // code that no longer exists.
    $tree = new SettableFingerprint;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'checks'))
            ->append(Shell::run('true', id: 'rewrites')->mutating());
    });

    $run = Run::start($pipeline->walk(), new RewritesWhenDeclared($tree), 'r-test', $tree);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toContain('after a check had already passed')
        ->and($run->allVerified())->toBeFalse();
});

it('verifies the fix chain, where rewriting steps come first', function (): void {
    // rector, then pint, then the checks — the default phase order. Both rewrites
    // land before anything has checked, so nothing is invalidated.
    $tree = new SettableFingerprint;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'rewrites')->mutating())
            ->append(Shell::run('true', id: 'checks'));
    });

    $run = Run::start($pipeline->walk(), new RewritesWhenDeclared($tree), 'r-test', $tree);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('does not invalidate a run when a rewriting step found nothing to rewrite', function (): void {
    // A clean `pint` after a passing check changed nothing, so nothing about the
    // earlier verdict is stale. Keying on the declaration alone would report a
    // false stale on every green run that carries a fix-mode step.
    $tree = new SettableFingerprint;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'checks'))
            ->append(Shell::run('true', id: 'rewrites-nothing')->mutating());
    });

    $run = Run::start($pipeline->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrentStep();
    $run->resolveCurrentStep();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});
