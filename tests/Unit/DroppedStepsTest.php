<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Walk\Walk;

/**
 * A step declared into a phase nothing registered is dropped from the walk.
 *
 * `notices` has always said so in prose. A sentence cannot say which SCOPE the
 * dropped step belonged to, so a scoped `pipeline:verify` could not refuse one —
 * applying the notice there would have failed a backend answer over a frontend
 * step. `dropped` is the same event as data, filtered during resolution where the
 * selection is known, which is the only place it can be.
 */
final class NoPhaseRegistersThis implements Phase
{
    public function id(): string
    {
        return 'unregistered-here';
    }

    public function name(): string
    {
        return 'Unregistered Here';
    }
}

/** Resolve a walk to completion with a runner that always passes. */
function runOne(Walk $walk, string $id, ?string $scope): Run
{
    $run = Run::start(
        $walk,
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        $id,
        scope: $scope,
    );

    $run->resolveCurrent();

    return $run;
}

/** One registered step, and one declared into a phase nothing registers. */
function walkDropping(?string $tag, ?string $selection): Walk
{
    return Pipeline::configure()->withSteps(function (Steps $steps) use ($tag): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept'));

        $orphan = Shell::run('true', id: 'orphan');
        $steps->in(NoPhaseRegistersThis::class)->append($tag === null ? $orphan : $orphan->tagged($tag));
    })->walk($selection);
}

it('names a dropped step for a whole-tree walk', function (): void {
    $walk = walkDropping(null, null);

    expect($walk->dropped)->toBe([['id' => 'orphan', 'phase' => 'NoPhaseRegistersThis']]);
});

it('names a dropped step that carries the selection', function (): void {
    $walk = walkDropping('backend', 'backend');

    expect($walk->dropped)->toBe([['id' => 'orphan', 'phase' => 'NoPhaseRegistersThis']]);
});

it('names an untagged dropped step for every selection', function (): void {
    // `selected()` puts an untagged step in every walk, so an untagged drop belongs
    // to every scope. The filter reuses that predicate rather than restating it.
    $walk = walkDropping(null, 'backend');

    expect($walk->dropped)->toBe([['id' => 'orphan', 'phase' => 'NoPhaseRegistersThis']]);
});

it('leaves out a dropped step that belongs to another selection', function (): void {
    // The false failure the scope exemption existed to avoid. A frontend step
    // declared into an unregistered phase says nothing about a backend answer.
    $walk = walkDropping('frontend', 'backend');

    expect($walk->dropped)->toBeEmpty();
});

it('drops nothing for a selection no step carries', function (): void {
    // The distinction the whole design turns on. This raises a NOTICE — the tag
    // matches nothing — but it drops no step: the walk is every untagged step. A
    // gate reading notices could not tell the two apart; reading `dropped` can.
    $walk = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept'));
    })->walk('nothing-carries-this');

    expect($walk->dropped)->toBeEmpty()
        ->and($walk->notices)->not->toBeEmpty();
});

it('leaves the prose notice exactly as it was', function (string $case, ?string $tag, ?string $selection): void {
    // `notices` is declared in the shared MCP envelope schema and read by agents.
    // The structured data arrives beside it, never in place of it, so the string and
    // its position must be untouched for every shape above.
    //
    // The FIRST notice, not the only one. A scoped walk here can carry a second:
    // when the only tagged step is the dropped one, the selection matches nothing
    // left in the walk, so the tag notice is added too — and correctly, because the
    // tag really does match nothing that will run.
    $walk = walkDropping($tag, $selection);

    expect($walk->notices[0] ?? null)
        ->toBe('Step(s) [orphan] dropped: declared into phase [NoPhaseRegistersThis], which is not registered.');
})->with([
    ['whole tree', null, null],
    ['in selection', 'backend', 'backend'],
    ['untagged', null, 'backend'],
    // Still says so, even where the drop is out of scope: the prose is about the
    // config, and the config really did declare a step nothing can reach.
    ['out of selection', 'frontend', 'backend'],
]);

it('carries exactly the one notice for a whole-tree walk', function (): void {
    // No selection, so no tag notice can arise: the drop is the only thing to say.
    expect(walkDropping(null, null)->notices)->toHaveCount(1);
});

it('adds the tag notice beside the drop when the selection matches nothing left', function (): void {
    // Pinned because the previous case reads the first notice only. Two notices for
    // one walk is correct here, and worth stating so a future reader does not treat
    // the second as a regression.
    $walk = walkDropping('backend', 'backend');

    expect($walk->notices)->toHaveCount(2)
        ->and($walk->notices[1])->toContain('No step carries the tag [backend]');
});

it('verifies a real scoped run whose only drop is out of scope', function (): void {
    // This asserted the opposite one release ago, and the change is deliberate:
    // accuracy over strictness. `all_verified` used to read the unfiltered
    // `notices`, so a scoped run was unverifiable while a step was dropped anywhere
    // in the config — including in a scope it never claimed to check.
    //
    // The gate had already been made scope-accurate, so the two disagreed: the
    // command tolerated an out-of-scope drop and the run's own verdict did not.
    // Now both answer for the scope asked about.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept')->tagged('backend'));
        $steps->in(NoPhaseRegistersThis::class)->append(Shell::run('true', id: 'orphan')->tagged('frontend'));
    });

    $walk = $pipeline->walk('backend');
    $run = runOne($walk, 'r-scoped-drop', 'backend');

    expect($walk->dropped)->toBeEmpty()
        // The prose notice still names the frontend drop. It reports what the
        // CONFIG got wrong, which does not depend on the scope asked about, and it
        // no longer decides whether the run verified.
        ->and($walk->notices)->not->toBeEmpty()
        ->and($run->allVerified())->toBeTrue();
});

it('refuses to verify a real scoped run whose drop IS in scope', function (): void {
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept')->tagged('backend'));
        $steps->in(NoPhaseRegistersThis::class)->append(Shell::run('true', id: 'orphan')->tagged('backend'));
    });

    $walk = $pipeline->walk('backend');
    $run = runOne($walk, 'r-in-scope-drop', 'backend');

    expect($walk->dropped)->not->toBeEmpty()
        ->and($run->allVerified())->toBeFalse();
});

it('still refuses to verify a run whose tag no step carries', function (): void {
    // The guard that accuracy must NOT eat. A mistyped tag drops nothing: the walk
    // is every untagged step, and those pass. Reading `dropped` alone would have
    // deleted this silently and left the run reporting itself verified while the
    // scope the caller asked about was never checked.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept'));
    });

    $walk = $pipeline->walk('bakend');
    $run = runOne($walk, 'r-typo', 'bakend');

    expect($walk->dropped)->toBeEmpty()
        ->and($walk->selectionCarriedNothing)->toBeTrue()
        ->and($run->allVerified())->toBeFalse();
});

it('records coverage complete for a scoped run whose drop is out of scope', function (): void {
    // `coverage` is measured the same way as `all_verified` and matters separately:
    // `pipeline:verify --server-verified` refuses anything that is not `complete`.
    // Left scope-blind it would have kept refusing the run the bare call now
    // accepts, which is the same disagreement one layer down.
    $path = sys_get_temp_dir().'/bp-cov-'.bin2hex(random_bytes(4)).'/receipt.json';
    $store = new JsonReceiptStore($path);

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept')->tagged('backend'));
        $steps->in(NoPhaseRegistersThis::class)->append(Shell::run('true', id: 'orphan')->tagged('frontend'));
    });

    $run = Run::start(
        $pipeline->walk('backend'),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-cov',
        receipts: $store,
        scope: 'backend',
    );

    $run->resolveCurrent();

    try {
        expect($store->read()?->coverage)->toBe('complete');
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('records coverage incomplete when the tag no step carries', function (): void {
    $path = sys_get_temp_dir().'/bp-cov-'.bin2hex(random_bytes(4)).'/receipt.json';
    $store = new JsonReceiptStore($path);

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept'));
    });

    $run = Run::start(
        $pipeline->walk('bakend'),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-cov-typo',
        receipts: $store,
        scope: 'bakend',
    );

    $run->resolveCurrent();

    try {
        expect($store->read()?->coverage)->toBe('incomplete');
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('refuses a run whose scope disagrees with the walk it was given', function (): void {
    // The false green this closes: a walk filtered for backend, a receipt told to
    // claim frontend, and a frontend step dropped. `dropped` is measured for the
    // backend selection and is empty, so the run reported all_verified: true about
    // a scope it never walked.
    //
    // It was masked before the verdict became scope-accurate, because the
    // unfiltered notice made any run with a drop anywhere unverifiable. Measuring
    // accurately removed that accident, so the rule is stated instead of restored.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept')->tagged('backend'));
        $steps->in(NoPhaseRegistersThis::class)->append(Shell::run('true', id: 'orphan')->tagged('frontend'));
    });

    expect(fn (): Run => runOne($pipeline->walk('backend'), 'r-mismatch', 'frontend'))
        ->toThrow(InvalidArgumentException::class, 'scope [frontend] but a walk resolved for [backend]');
});

it('refuses an unscoped claim over a scoped walk, and the reverse', function (): void {
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept')->tagged('backend'));
    });

    // Both directions, because either leaves the receipt describing the wrong set
    // of steps. The message names the whole tree rather than printing "null".
    expect(fn (): Run => runOne($pipeline->walk('backend'), 'r-a', null))
        ->toThrow(InvalidArgumentException::class, 'the whole tree')
        ->and(fn (): Run => runOne($pipeline->walk(), 'r-b', 'backend'))
        ->toThrow(InvalidArgumentException::class, 'scope [backend]');
});

it('carries the selection it was resolved with, so the two can be compared at all', function (): void {
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept')->tagged('backend'));
    });

    expect($pipeline->walk('backend')->selection)->toBe('backend')
        ->and($pipeline->walk()->selection)->toBeNull();
});
