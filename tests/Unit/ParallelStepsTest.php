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
use SanderMuller\BoostPipeline\Walk\WalkStep;

/**
 * A parallel group is one position in the walk. The rules that keep it honest are
 * enforced when the config loads, not when the run reaches it, so a bad group
 * names its offending step before any work is paid for.
 */
/** @return list<WalkStep> */
function parallelWalk(Closure $group): array
{
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps) use ($group): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'lone'));
            $steps->in(StaticAnalysis::class)->parallel($group);
        })
        ->walk();

    return $walk->steps;
}

it('gives every step in a group the same position', function (): void {
    $steps = parallelWalk(function (StepCollection $steps): void {
        $steps->append(Shell::run('composer phpstan', id: 'phpstan'));
        $steps->append(Shell::run('tsc --noEmit', id: 'tsc'));
    });

    expect($steps[0]->batchId)->toBeNull()
        ->and($steps[1]->batchId)->not->toBeNull()
        ->and($steps[2]->batchId)->toBe($steps[1]->batchId);
});

it('keeps two groups in one phase apart', function (): void {
    // Sharing an id would silently merge them into a single position, running
    // four commands at once where the config asked for two then two.
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(StaticAnalysis::class)
                ->parallel(function (StepCollection $steps): void {
                    $steps->append(Shell::run('true', id: 'a'));
                    $steps->append(Shell::run('true', id: 'b'));
                })
                ->parallel(function (StepCollection $steps): void {
                    $steps->append(Shell::run('true', id: 'c'));
                    $steps->append(Shell::run('true', id: 'd'));
                });
        })
        ->walk();

    expect($walk->steps[0]->batchId)->toBe($walk->steps[1]->batchId)
        ->and($walk->steps[2]->batchId)->toBe($walk->steps[3]->batchId)
        ->and($walk->steps[0]->batchId)->not->toBe($walk->steps[2]->batchId);
});

it('reports the whole group as the position, and a lone step as itself', function (): void {
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'lone'));
            $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
                $steps->append(Shell::run('true', id: 'a'));
                $steps->append(Shell::run('true', id: 'b'));
            });
        })
        ->walk();

    expect($walk->positionAt(0))->toHaveCount(1)
        ->and($walk->positionAt(1))->toHaveCount(2)
        ->and(array_map(fn (WalkStep $s): string => $s->step->id(), $walk->positionAt(1)))
        ->toBe(['a', 'b']);
});

it('counts grouped steps individually, because each still earns its own verdict', function (): void {
    $steps = parallelWalk(function (StepCollection $steps): void {
        $steps->append(Shell::run('true', id: 'a'));
        $steps->append(Shell::run('true', id: 'b'));
    });

    expect($steps)->toHaveCount(3);
});

it('refuses a skill step in a group', function (): void {
    // Several lenses handed over at once is the wall of context the cursor exists
    // to break up, and the server cannot fan them out to separate contexts.
    parallelWalk(function (StepCollection $steps): void {
        $steps->append(Shell::run('true', id: 'a'));
        $steps->append(Skill::run('/code-review'));
    });
})->throws(InvalidPipelineConfigException::class, 'only shell steps can');

it('refuses a mutating step in a group', function (): void {
    // Siblings would run against a tree it is rewriting, with no ordering to
    // attribute the change to, so every sibling verdict would describe code that
    // no longer exists.
    parallelWalk(function (StepCollection $steps): void {
        $steps->append(Shell::run('vendor/bin/pint', id: 'fix')->mutating());
    });
})->throws(InvalidPipelineConfigException::class, 'cannot run in a parallel group');

it('refuses a group inside a group', function (): void {
    parallelWalk(function (StepCollection $steps): void {
        $steps->parallel(function (StepCollection $steps): void {
            $steps->append(Shell::run('true', id: 'a'));
        });
    });
})->throws(InvalidPipelineConfigException::class, 'cannot contain another parallel group');

it('still rejects a duplicate id across a group boundary', function (): void {
    parallelWalk(function (StepCollection $steps): void {
        $steps->append(Shell::run('true', id: 'lone'));
    });
})->throws(InvalidPipelineConfigException::class, 'Duplicate step id [lone]');

it('leaves an empty group as no position at all', function (): void {
    $steps = parallelWalk(function (StepCollection $steps): void {});

    expect($steps)->toHaveCount(1);
});

it('does not put a skill step anywhere near a group by accident', function (): void {
    // A skill step declared normally in the same phase is unaffected.
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
                $steps->append(Shell::run('true', id: 'a'));
                $steps->append(Shell::run('true', id: 'b'));
            });
            $steps->in(Agent::class)->append(Skill::run('/evaluate'));
        })
        ->walk();

    expect($walk->positionAt(2))->toHaveCount(1)
        ->and($walk->positionAt(2)[0]->step->id())->toBe('evaluate');
});
