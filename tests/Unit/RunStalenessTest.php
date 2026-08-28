<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

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

    $run->resolveCurrent();
    $run->resolveCurrent();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('still verifies a run whose steps declared that they rewrite code', function (): void {
    // `pint` and `rector process` change the tree as their normal job. Counting
    // that against the run would report stale on every clean run using one, and a
    // gate that cries stale when nothing is wrong stops being read.
    $tree = new SettableFingerprint;
    $run = Run::start(declaredMutatingPipeline()->walk(), new RewritesTheTree($tree), 'r-test', $tree);

    $run->resolveCurrent();
    $run->resolveCurrent();

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

    $run->resolveCurrent();
    $run->resolveCurrent();

    expect($run->staleReason())->toContain('Step [first] measured a different working tree')
        ->and($run->allVerified())->toBeFalse();
});

it('refuses to verify when the tree changed between two steps', function (): void {
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrent();

    // Nothing ran in this gap, so the change is the agent's: the first step's
    // verdict now describes code that is no longer on disk.
    $tree->value = 'edited';

    $run->resolveCurrent();

    expect($run->staleReason())->toContain('Step [first] measured a different working tree')
        ->and($run->allVerified())->toBeFalse();
});

it('refuses to verify when the tree changed after the walk finished', function (): void {
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrent();
    $run->resolveCurrent();

    expect($run->allVerified())->toBeTrue();

    $tree->value = 'edited-after';

    expect($run->staleReason())->toContain('measured a different working tree')
        ->and($run->allVerified())->toBeFalse();
});

it('does not expire anything when the tree cannot be fingerprinted', function (): void {
    // No git, no digest. Behaviour matches what it was before fingerprinting
    // existed, rather than a run that can never be verified.
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', new SettableFingerprint(null));

    $run->resolveCurrent();
    $run->resolveCurrent();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('hands back the same run while the tree sits still', function (): void {
    $manager = runManagerFor(twoStepPipeline(), new AlwaysPasses, new SettableFingerprint);

    $first = $manager->open();
    $first->resolveCurrent();

    expect($manager->open()->id)->toBe($first->id);
});

it('starts a fresh run once the tree has moved, which is what the fix loop needs', function (): void {
    // Run, see a failure, change the code, verify again. Returning the first run
    // here hands back verdicts about code that no longer exists; refusing to open
    // a second one makes the loop impossible without restarting the server.
    $tree = new SettableFingerprint;
    $manager = runManagerFor(twoStepPipeline(), new AlwaysPasses, $tree);

    $first = $manager->open();
    $first->resolveCurrent();

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

    $run->resolveCurrent();
    $run->resolveCurrent();

    expect($run->staleReason())->toContain('Step [checks] measured a different working tree')
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

    $run->resolveCurrent();
    $run->resolveCurrent();

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

    $run->resolveCurrent();
    $run->resolveCurrent();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('does not hold a fixed run stale for having been fixed', function (): void {
    // The fix loop, in place: the step fails, you edit the code, next_step retries
    // it and it passes. A run-level flag stayed stuck on the edit and reported the
    // finished run stale — defeating the very loop this release exists to enable.
    $tree = new SettableFingerprint;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'only'));
    });

    $failsUntilFixed = new class implements StepRunner
    {
        public function __construct(public int $attempts = 0) {}

        public function run(Step $step, string $runId): Result
        {
            $this->attempts++;

            return $this->attempts === 1
                ? Result::failed($step->id(), 'problems found', 1)
                : Result::passed($step->id(), 'ok');
        }
    };

    $run = Run::start($pipeline->walk(), $failsUntilFixed, 'r-test', $tree);

    $run->resolveCurrent();

    $tree->value = 'the-fix';
    $run->resolveCurrent();

    expect($run->staleReason())->toBeNull()
        ->and($run->allVerified())->toBeTrue();
});

it('credits a declared mutating skill for the edit it was asked to make', function (): void {
    // The agent edits during the skill, then calls report_step. Treating that as
    // unexplained made a /evaluate step permanently stale — and a fixing skill
    // changing code is the normal case, not the exception.
    $tree = new SettableFingerprint;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate')->mutating());
    });

    $run = Run::start($pipeline->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrent();

    $tree->value = 'fixed-by-evaluate';
    $run->acknowledgeCurrentStep('fixed two things');

    expect($run->staleReason())->toBeNull();
});

it('does not treat an acknowledgement as a check a later rewrite invalidates', function (): void {
    // An acknowledgement was never verified, so a rewrite after one invalidates
    // nothing. Reporting stale there was a message about a receipt that claims
    // nothing.
    $tree = new SettableFingerprint;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/code-review'));
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'rewrites')->mutating());
    });

    $run = Run::start($pipeline->walk(), new RewritesWhenDeclared($tree), 'r-test', $tree);

    $run->resolveCurrent();
    $run->acknowledgeCurrentStep('reviewed');
    $run->resolveCurrent();

    expect($run->staleReason())->toBeNull();
});

it('keeps the run id when the tree moves before anything has been recorded', function (): void {
    // Nothing recorded means nothing to invalidate, so an edit while the agent is
    // still deciding what to run should not churn through run ids.
    $tree = new SettableFingerprint;
    $manager = runManagerFor(twoStepPipeline(), new AlwaysPasses, $tree);

    $first = $manager->open();
    $tree->value = 'edited-before-any-step';

    expect($manager->open()->id)->toBe($first->id);
});

it('refuses to verify when a numeric step id measured a tree that moved', function (): void {
    // A numeric string id ('123') coerces to an int array key wherever it is
    // used as a foreach key, so $this->measuredAt keys it as an int too. The
    // staleness check must survive that instead of throwing a TypeError.
    $tree = new SettableFingerprint;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: '123'))
            ->append(Shell::run('true', id: 'second'));
    });

    $run = Run::start($pipeline->walk(), new AlwaysPasses, 'r-test', $tree);

    $run->resolveCurrent();

    $tree->value = 'edited';

    $run->resolveCurrent();

    expect($run->staleReason())->toContain('Step [123] measured a different working tree')
        ->and($run->allVerified())->toBeFalse();
});

it('never reports verified and stale from different readings of the tree', function (): void {
    // Asking separately let the tree move between the two questions, so one
    // payload could carry all_verified: true beside a stale message. A fingerprint
    // that changes on every read is the sharpest version of that race.
    $shifting = new class implements TreeFingerprint
    {
        private int $reads = 0;

        public function capture(): string
        {
            return 'tree-'.$this->reads++;
        }
    };

    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-test', $shifting);
    $run->resolveCurrent();
    $run->resolveCurrent();

    $verification = $run->verification();

    expect($verification['all_verified'] && $verification['stale'] !== null)->toBeFalse();
});

it('names the moved commit as a cause, not just an edit', function (): void {
    // The fingerprint is HEAD plus everything uncommitted, so a commit, amend,
    // checkout or rebase moves it with no file changed and nothing to undo. A
    // consumer who amended mid-walk went looking for an edit that never happened,
    // because the message enumerated two causes for a three-cause condition.
    $tree = new SettableFingerprint;
    $run = Run::start(twoStepPipeline()->walk(), new AlwaysPasses, 'r-amend', $tree);

    $run->resolveCurrent();

    $tree->value = 'moved';

    expect($run->staleReason())->toContain('the commit moved')
        ->and($run->staleReason())->toContain('amend')
        ->and($run->staleReason())->toContain('no file to change');
});
