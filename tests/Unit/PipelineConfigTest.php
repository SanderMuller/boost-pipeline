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
})->throws(InvalidPipelineConfigException::class);

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

it('splices a transition step in at the join between its two anchors', function (): void {
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('composer phpstan'));
            $steps->between(
                Formatting::class,
                StaticAnalysis::class,
                Shell::run('git diff --quiet -- composer.json composer.lock', id: 'deps-unchanged'),
            );
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))
        ->toBe(['pint', 'deps-unchanged', 'phpstan'])
        ->and($walk->notices)
        ->toBeEmpty();
});

it('drops a transition whose anchor was removed, and reports it rather than promoting it', function (): void {
    $walk = Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->remove(StaticAnalysis::class);
        })
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
            $steps->between(
                Formatting::class,
                StaticAnalysis::class,
                Shell::run('true', id: 'deps-unchanged'),
            );
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))->toBe(['pint'])
        ->and($walk->notices)->toHaveCount(1)
        ->and($walk->notices[0])->toContain('deps-unchanged')
        ->and($walk->notices[0])->toContain('dropped');
});

it('fails loudly on a duplicate step id across phases', function (): void {
    Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
            $steps->in(Tests::class)->append(Shell::run('some/other/pint'));
        })
        ->walk();
})->throws(InvalidPipelineConfigException::class, 'Duplicate step id [pint]');

it('reports a transition whose anchors are registered but not adjacent', function (): void {
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Refactoring::class)->append(Shell::run('vendor/bin/rector process --dry-run'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('composer phpstan'));
            // Formatting sits between these two, so there is no such join.
            $steps->between(Refactoring::class, StaticAnalysis::class, Shell::run('true', id: 'non-adjacent'));
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))
        ->toBe(['rector', 'phpstan'])
        ->and($walk->notices)->toHaveCount(1)
        ->and($walk->notices[0])->toContain('non-adjacent')
        ->and($walk->notices[0])->toContain('not adjacent')
        ->and($walk->notices[0])->toContain('Formatting');
});

it('reports a transition whose anchors are the wrong way round', function (): void {
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
            $steps->between(StaticAnalysis::class, Formatting::class, Shell::run('true', id: 'reversed'));
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))->toBe(['pint'])
        ->and($walk->notices)->toHaveCount(1)
        ->and($walk->notices[0])->toContain('reversed')
        ->and($walk->notices[0])->toContain('comes before');
});

it('names the missing anchor precisely when only one is registered', function (): void {
    $walk = Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->remove(Tests::class);
        })
        ->withSteps(function (Steps $steps): void {
            $steps->between(StaticAnalysis::class, Tests::class, Shell::run('true', id: 'orphan'));
        })
        ->walk();

    expect($walk->notices[0])->toContain('[Tests] is not registered');
});

it('says "after itself" rather than "not registered" for a self-anchor', function (): void {
    Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->append(ImpactAnalysis::class)->after(ImpactAnalysis::class);
        });
})->throws(InvalidPipelineConfigException::class, 'after itself');

it('reports steps declared into a phase that is not registered', function (): void {
    // Same class of silent loss as a dropped transition: removing a phase would
    // otherwise take its gates down without a word.
    $walk = Pipeline::configure()
        ->withPhases(function (Phases $phases): void {
            $phases->remove(Refactoring::class);
        })
        ->withSteps(function (Steps $steps): void {
            $steps->in(Refactoring::class)->append(Shell::run('vendor/bin/rector process --dry-run'));
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
        })
        ->walk();

    expect(array_map(fn (WalkStep $walkStep): string => $walkStep->step->id(), $walk->steps))->toBe(['pint'])
        ->and($walk->notices)->toHaveCount(1)
        ->and($walk->notices[0])->toContain('[rector]')
        ->and($walk->notices[0])->toContain('Refactoring')
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
