<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
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

it('records all_verified false for a real scoped run whose drop is out of scope', function (): void {
    // The end-to-end truth behind the command-level test that tolerates this. The
    // walk correctly reports no in-scope drop, and the run still refuses to call
    // itself verified — because `Run` reads `notices`, which is not scope-filtered.
    //
    // Both are defensible on their own and they disagree, which is the substance of
    // this spec's one open question. Pinned here so the disagreement is a recorded
    // fact rather than something a future reader rediscovers.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'kept')->tagged('backend'));
        $steps->in(NoPhaseRegistersThis::class)->append(Shell::run('true', id: 'orphan')->tagged('frontend'));
    });

    $walk = $pipeline->walk('backend');

    $run = Run::start(
        $walk,
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-scoped-drop',
        scope: 'backend',
    );

    $run->resolveCurrent();

    expect($walk->dropped)->toBeEmpty()
        ->and($walk->notices)->not->toBeEmpty()
        ->and($run->allVerified())->toBeFalse();
});
