<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * The selection reaches the walk through the tool, and the payload says what is
 * in play. A scoped run that looked like a full one would be the false green this
 * whole feature has to avoid.
 */
beforeEach(function (): void {
    $this->configPath = app()->basePath('.config/pipeline.php');

    if (! is_dir(dirname($this->configPath))) {
        mkdir(dirname($this->configPath), recursive: true);
    }

    file_put_contents($this->configPath, '<?php return '.Pipeline::class.'::configure();');

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('true', id: 'pint')->tagged('backend'))
            ->append(Shell::run('true', id: 'oxlint')->tagged('frontend'));

        $steps->in(StaticAnalysis::class)
            ->append(Shell::run('true', id: 'phpstan')->tagged('backend'))
            ->append(Shell::run('true', id: 'tsc')->tagged('frontend'));
    });

    $this->manager = runManagerFor($pipeline, new class implements StepRunner
    {
        public function run(Step $step, string $runId): Result
        {
            return Result::passed($step->id(), 'ok');
        }
    });

    app()->instance(RunManager::class, $this->manager);
});

afterEach(function (): void {
    if (is_file($this->configPath)) {
        unlink($this->configPath);
    }
});

it('walks only the selected scope and says which one', function (): void {
    PipelineServer::tool(OpenRun::class, ['only' => 'backend'])
        ->assertOk()
        ->assertSee('"scope":"backend"')
        ->assertSee('"total_steps":2')
        ->assertSee('pint');
});

it('says nothing about scope when the whole pipeline runs', function (): void {
    // Absent rather than null, so a consumer that never asks for a scope sees
    // exactly the payload it saw before scopes existed.
    PipelineServer::tool(OpenRun::class)
        ->assertOk()
        ->assertSee('"total_steps":4')
        // The JSON key, not the bare word: a step id or description containing
        // "scope" would otherwise fail this for the wrong reason.
        ->assertDontSee('"scope"');
});

it('treats an empty string as no selection at all', function (): void {
    PipelineServer::tool(OpenRun::class, ['only' => '   '])
        ->assertOk()
        ->assertSee('"total_steps":4');
});

it('reports how many steps the scope left out', function (): void {
    PipelineServer::tool(OpenRun::class, ['only' => 'backend']);

    PipelineServer::tool(Status::class)
        ->assertOk()
        ->assertSee('"excluded_by_scope":2');
});

it('starts a new run when the selection changes', function (): void {
    // A different selection asks a different question, so handing back the open
    // run would answer the wrong one.
    $backend = $this->manager->open(selection: 'backend');
    $frontend = $this->manager->open(selection: 'frontend');

    expect($frontend->id)->not->toBe($backend->id)
        ->and($frontend->scope)->toBe('frontend')
        ->and($frontend->walk->count())->toBe(2);
});

it('starts a new run when a scoped run is reopened unscoped', function (): void {
    $scoped = $this->manager->open(selection: 'backend');
    $full = $this->manager->open();

    expect($full->id)->not->toBe($scoped->id)
        ->and($full->scope)->toBeNull()
        ->and($full->walk->count())->toBe(4);
});

it('keeps returning the same run for the same selection', function (): void {
    expect($this->manager->open(selection: 'backend')->id)->toBe($this->manager->open(selection: 'backend')->id);
});

it('surfaces the bad-scope notice through the tool and refuses to call the run verified', function (): void {
    // The safety property the whole feature turns on. A scope nothing carries
    // leaves the untagged steps to pass, and without the notice the run would
    // report itself verified while the scope asked about was never checked.
    PipelineServer::tool(OpenRun::class, ['only' => 'bakend'])
        ->assertOk()
        ->assertSee('notices')
        ->assertSee('[bakend]');

    $run = $this->manager->for();
    $run?->resolveCurrent();

    expect($run?->allVerified())->toBeFalse();
});

it('names an unquoted shell variable as the likelier cause than a typo', function (): void {
    // The reported shape: an unquoted `$SCOPE` in a shell command word-split into
    // the next flag, so the selection arrived as `backend --server-verified`.
    // "Check the spelling" sends the reader hunting a typo in something that was
    // never a tag, and every surface reads this one notice.
    PipelineServer::tool(OpenRun::class, ['only' => 'backend --server-verified'])
        ->assertOk()
        ->assertSee('unquoted shell variable');
});
