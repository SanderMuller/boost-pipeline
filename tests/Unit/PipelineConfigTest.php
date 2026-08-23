<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\Refactoring;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;
use SanderMuller\BoostPipeline\Phases\Phases;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;
use SanderMuller\BoostPipeline\Walk\WalkStep;

final class ImpactAnalysis implements Phase
{
    public function id(): string
    {
        return 'impact-analysis';
    }

    public function name(): string
    {
        return 'Impact analysis';
    }
}

it('registers the five defaults in order', function (): void {
    $phases = new Phases;

    expect($phases->all())->toBe([
        Refactoring::class,
        Formatting::class,
        StaticAnalysis::class,
        Tests::class,
        Agent::class,
    ]);
});

it('ships no steps by default, so an out-of-the-box pipeline is immediately complete', function (): void {
    $walk = Pipeline::configure()->walk();

    expect($walk->isEmpty())->toBeTrue()
        ->and($walk->count())->toBe(0)
        ->and($walk->at(0))->toBeNull();
});

it('drops a phase with remove', function (): void {
    $pipeline = Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->remove(Refactoring::class);
        });

    expect($pipeline->phases()->all())->not->toContain(Refactoring::class)
        ->and($pipeline->phases()->all())->toHaveCount(4);
});

it('lands a custom phase directly after its anchor', function (): void {
    $pipeline = Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->append(ImpactAnalysis::class)->after(StaticAnalysis::class);
        });

    expect($pipeline->phases()->all())->toBe([
        Refactoring::class,
        Formatting::class,
        StaticAnalysis::class,
        ImpactAnalysis::class,
        Tests::class,
        Agent::class,
    ]);
});

it('fails loudly when positioning after a phase that is not registered', function (): void {
    Pipeline::configure()->withPhases(function (Phases $phases): void {
        $phases->remove(StaticAnalysis::class);
        $phases->append(ImpactAnalysis::class)->after(StaticAnalysis::class);
    });
})->throws(InvalidPipelineConfigException::class, 'no such phase is registered');

it('fails loudly when a phase is positioned after itself', function (): void {
    Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->append(ImpactAnalysis::class)->after(ImpactAnalysis::class);
        });
})->throws(InvalidPipelineConfigException::class, 'after itself');

it('lets a review pipeline name its own phases, which is why the set is open', function (): void {
    // The reason `withPhases()` came back. Six review lenses all reporting
    // phase "Agent" tells a reader nothing, and the package cannot know a
    // project's review vocabulary.
    $walk = Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->remove(Refactoring::class);
            $phases->remove(Formatting::class);
            $phases->remove(StaticAnalysis::class);
            $phases->remove(Tests::class);
            $phases->prepend(ImpactAnalysis::class);
        })
        ->withSteps(function (Steps $steps): void {
            $steps->in(ImpactAnalysis::class)->append(
                Skill::run('/code-review', id: 'blast-radius', instruction: 'Name what this change can break.'),
            );
            $steps->in(Agent::class)->append(
                Skill::run('/code-review', id: 'tests', instruction: 'Judge the test coverage of the change only.'),
            );
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->phaseName, $walk->steps))
        ->toBe(['Impact analysis', 'Agent']);
});

it("walks each phase's steps in order, skipping empty phases silently", function (): void {
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)
                ->append(Shell::run('vendor/bin/pint --test'))
                ->append(Shell::run('yarn lint-all'));
            $steps->in(Agent::class)->append(Skill::run('/evaluate'));
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))
        ->toBe(['pint', 'lint-all', 'evaluate'])
        ->and($walk->notices)
        ->toBeEmpty();
});

it('prepend puts a step first within its phase', function (): void {
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(StaticAnalysis::class)
                ->append(Shell::run('composer phpstan'))
                ->prepend(Shell::run('yarn typecheck'));
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))
        ->toBe(['typecheck', 'phpstan']);
});

it('fails loudly on a duplicate step id across phases', function (): void {
    Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
            $steps->in(Tests::class)->append(Shell::run('some/other/pint'));
        })
        ->walk();
})->throws(InvalidPipelineConfigException::class, 'Duplicate step id [pint]');

it('reports steps declared into a phase that is not registered', function (): void {
    // The only way a declared step can be lost, and it must never be lost in
    // silence: a typo'd phase class would otherwise take its gate down without a
    // word.
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(ImpactAnalysis::class)->append(Shell::run('vendor/bin/rector process --dry-run'));
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))->toBe(['pint'])
        ->and($walk->notices)->toHaveCount(1)
        ->and($walk->notices[0])->toContain('[rector]')
        ->and($walk->notices[0])->toContain('ImpactAnalysis')
        ->and($walk->notices[0])->toContain('not registered');
});

it('carries a default timeout for steps that do not set their own', function (): void {
    expect(Pipeline::configure()->timeoutSeconds())->toBeNull()
        ->and(Pipeline::configure()->withTimeout(900.0)->timeoutSeconds())->toBe(900.0);
});

it('refuses a timeout of zero, which would remove the ceiling rather than tighten it', function (): void {
    // Symfony's runner treats zero as no limit, so accepting it would turn a
    // configured cap into no cap at all.
    expect(fn (): Pipeline => Pipeline::configure()->withTimeout(0))
        ->toThrow(InvalidPipelineConfigException::class, 'must be greater than zero');
});

it('refuses a negative or zero per-step timeout too', function (): void {
    expect(fn (): Shell => Shell::run('true')->timeout(0))
        ->toThrow(InvalidPipelineConfigException::class)
        ->and(fn (): Shell => Shell::run('true')->timeout(-5))
        ->toThrow(InvalidPipelineConfigException::class);
});
