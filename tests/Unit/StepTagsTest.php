<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;
use SanderMuller\BoostPipeline\Walk\Walk;
use SanderMuller\BoostPipeline\Walk\WalkStep;

/**
 * A scoped walk deliberately leaves declared steps out, which is the one thing
 * the rest of this package exists to prevent. The line that keeps it honest is
 * the difference between a step EXCLUDED by a selection, which is silent and
 * intended, and a step DROPPED by a fault, which is reported and blocks.
 */
function tagged(?string $selection, Closure $declare): Walk
{
    return Pipeline::configure()->withSteps($declare)->walk($selection);
}

/** @return list<string> */
function idsOf(Walk $walk): array
{
    return array_map(static fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps);
}

it('walks every step when nothing is selected', function (): void {
    $walk = tagged(null, function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'plain'))
            ->append(Shell::run('true', id: 'front')->tagged('frontend'))
            ->append(Shell::run('true', id: 'back')->tagged('backend'));
    });

    expect(idsOf($walk))->toBe(['plain', 'front', 'back'])
        ->and($walk->notices)->toBeEmpty();
});

it('keeps untagged steps in every selection', function (): void {
    // Untagged means "always", never "never". If it meant the opposite, adding
    // the first tag to one step would silently drop every step carrying none.
    $walk = tagged('backend', function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'plain'))
            ->append(Shell::run('true', id: 'front')->tagged('frontend'))
            ->append(Shell::run('true', id: 'back')->tagged('backend'));
    });

    expect(idsOf($walk))->toBe(['plain', 'back'])
        ->and($walk->notices)->toBeEmpty();
});

it('matches a step carrying several tags on any one of them', function (): void {
    $steps = fn (Steps $steps): mixed => $steps->in(StaticAnalysis::class)
        ->append(Shell::run('true', id: 'phpstan')->tagged('backend', 'slow'));

    expect(idsOf(tagged('backend', $steps)))->toBe(['phpstan'])
        ->and(idsOf(tagged('slow', $steps)))->toBe(['phpstan'])
        ->and(idsOf(tagged('frontend', $steps)))
        ->toBeEmpty();
});

it('matches case-sensitively, so a mistyped case narrows nothing silently', function (): void {
    $walk = tagged('Backend', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'back')->tagged('backend'));
    });

    expect(idsOf($walk))
        ->toBeEmpty()
        ->and($walk->notices)->toHaveCount(1);
});

it('reports a selection no step carries, because that is a typo not a scope', function (): void {
    // The untagged steps would otherwise pass and the run would call itself
    // verified while the scope asked about was never checked at all.
    $walk = tagged('bakend', function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'plain'))
            ->append(Shell::run('true', id: 'back')->tagged('backend'));
    });

    expect(idsOf($walk))->toBe(['plain'])
        ->and($walk->notices)->toHaveCount(1)
        ->and($walk->notices[0])->toContain('[bakend]')
        ->and($walk->notices[0])->toContain('case-sensitive');
});

it('says nothing when the selection matched, even if it matched only one step', function (): void {
    $walk = tagged('backend', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'back')->tagged('backend'));
    });

    expect($walk->notices)->toBeEmpty();
});

it('filters inside a parallel group and keeps the survivors together', function (): void {
    $walk = tagged('backend', function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
            $steps->append(Shell::run('true', id: 'phpstan')->tagged('backend'));
            $steps->append(Shell::run('true', id: 'psalm')->tagged('backend'));
            $steps->append(Shell::run('true', id: 'tsc')->tagged('frontend'));
        });
    });

    expect(idsOf($walk))->toBe(['phpstan', 'psalm'])
        ->and($walk->steps[0]->batchId)->not->toBeNull()
        ->and($walk->steps[1]->batchId)->toBe($walk->steps[0]->batchId);
});

it('stops calling a group a group once one member survives the filter', function (): void {
    // `isGrouped()` reads batchId, and a stale verdict for a step that ran alone
    // would otherwise claim it shared a measurement with siblings that were never
    // in the walk. That is the overclaim removed in an earlier release, by another
    // route.
    $walk = tagged('backend', function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
            $steps->append(Shell::run('true', id: 'phpstan')->tagged('backend'));
            $steps->append(Shell::run('true', id: 'tsc')->tagged('frontend'));
        });
    });

    expect(idsOf($walk))->toBe(['phpstan'])
        ->and($walk->steps[0]->batchId)->toBeNull()
        ->and($walk->isGrouped('phpstan'))->toBeFalse();
});

it('drops a group whose every member was filtered out', function (): void {
    $walk = tagged('backend', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
        $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
            $steps->append(Shell::run('true', id: 'tsc')->tagged('frontend'));
            $steps->append(Shell::run('true', id: 'oxlint')->tagged('frontend'));
        });
    });

    expect(idsOf($walk))->toBe(['pint'])
        ->and($walk->notices)->toBeEmpty();
});

it('reports a scope that exists nowhere, even when other tags do', function (): void {
    // The shape that catches people out: tagging only the steps you want to
    // exclude does not give you a name for the rest. To select a scope, some step
    // has to carry it, so both sides get a tag.
    $walk = tagged('backend', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'tsc')->tagged('frontend'));
    });

    expect(idsOf($walk))->toBe(['pint'])
        ->and($walk->notices)->toHaveCount(1)
        ->and($walk->notices[0])->toContain('[backend]');
});

it('tags a skill step too, and keeps the tag through mutating and proving', function (): void {
    $step = Skill::run('/evaluate', instruction: 'Fix it.')
        ->tagged('backend')
        ->mutating()
        ->proving('true');

    expect($step->tags())->toBe(['backend'])
        ->and($step->mutates())->toBeTrue()
        ->and($step->proof())->toBe('true');
});

it('excludes a skill step by selection like any other', function (): void {
    $walk = tagged('backend', function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/eye-verify')->tagged('frontend'));
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint'));
    });

    expect(idsOf($walk))->toBe(['pint']);
});

it('refuses an empty tag when the config loads', function (): void {
    Shell::run('true', id: 'x')->tagged('  ');
})->throws(InvalidPipelineConfigException::class, 'declares an empty tag');

it('refuses an empty tag on a skill step too', function (): void {
    Skill::run('/x')->tagged('');
})->throws(InvalidPipelineConfigException::class, 'declares an empty tag');

it('does not duplicate a tag declared twice', function (): void {
    expect(Shell::run('true', id: 'x')->tagged('backend')->tagged('backend')->tags())
        ->toBe(['backend']);
});
