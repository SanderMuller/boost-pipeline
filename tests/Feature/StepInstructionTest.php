<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Steps\Skill;

/**
 * The instruction is the whole point of handing over one step at a time.
 *
 * A step delivered as a bare `/code-review` makes the agent run a broad skill,
 * which then presents its own list of concerns — so the wall of context the
 * cursor exists to break up reappears inside the step. Narrowing the step to one
 * lens is what buys the focus, and the field carrying it was reachable from
 * config and read by nothing at all.
 */
function serveSkillSteps(Skill ...$skills): void
{
    $configPath = app()->basePath('.config/pipeline.php');

    if (! is_dir(dirname($configPath))) {
        mkdir(dirname($configPath), recursive: true);
    }

    file_put_contents($configPath, '<?php return '.Pipeline::class.'::configure();');

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps) use ($skills): void {
        foreach ($skills as $skill) {
            $steps->in(Agent::class)->append($skill);
        }
    });

    app()->instance(RunManager::class, runManagerFor($pipeline, resolve(StepRunner::class)));
}

afterEach(function (): void {
    $configPath = app()->basePath('.config/pipeline.php');

    if (is_file($configPath)) {
        unlink($configPath);
    }
});

it('hands the agent the instruction, not just the skill to invoke', function (): void {
    serveSkillSteps(Skill::run(
        '/code-review',
        id: 'review-errors',
        instruction: 'Review only the error handling in files changed since main. Ignore style and tests.',
    ));

    PipelineServer::tool(OpenRun::class)
        ->assertOk()
        ->assertSee('Review only the error handling')
        ->assertSee('Ignore style and tests');
});

it('falls back to naming the invocation when no instruction is given', function (): void {
    // A step with nothing to narrow it still has to say what to do.
    serveSkillSteps(Skill::run('/evaluate'));

    PipelineServer::tool(OpenRun::class)
        ->assertOk()
        ->assertSee('Invoke /evaluate');
});

it('keeps the instruction through mutating() and proving()', function (): void {
    // Both return a new instance, so a dropped field here would silently empty
    // the step of the only thing telling the agent what to look at.
    serveSkillSteps(
        Skill::run('/fix', id: 'fix', instruction: 'Repair the failing assertions only.')
            ->mutating()
            ->proving('true'),
    );

    PipelineServer::tool(OpenRun::class)
        ->assertOk()
        ->assertSee('Repair the failing assertions only');
});

it('states the guarantee it has rather than the one it lacks', function (): void {
    serveSkillSteps(Skill::run('/code-review', instruction: 'Look at concurrency.'));

    PipelineServer::tool(OpenRun::class)
        ->assertOk()
        // Acknowledged is the normal outcome for judgement work, so the note
        // leads with what the server does promise: this step arrived alone, in
        // order, and nothing follows until it resolves.
        ->assertSee('Nothing else is handed over until you do')
        ->assertSee('acknowledged rather than verified');
});
