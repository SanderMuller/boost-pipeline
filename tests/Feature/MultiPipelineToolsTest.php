<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\ReportStep;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\CommandPreflight;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * The tools, against a project that declares several pipelines.
 *
 * The risk this file exists for is the wrong cursor advancing. A `next_step`
 * that guessed a pipeline would run the wrong steps and write a verdict into the
 * wrong receipt, silently — a worse failure than any error message, so every
 * omission and every wrong name has to be an error here.
 */
function declareMap(): void
{
    $pipelines = Pipelines::fromArray([
        'pr' => Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test', id: 'pint'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('composer phpstan', id: 'phpstan'));
        }),
        'release' => Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(StaticAnalysis::class)->append(Shell::run('composer audit', id: 'audit'));
        }),
    ], '.config/pipeline.php');

    app()->instance(Pipelines::class, $pipelines);

    app()->instance(RunManager::class, new RunManager(
        $pipelines,
        new StepRunnerFactory(static fn (string $name): StepRunner => new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        }),
        null,
        new ReceiptStoreFactory(static fn (string $name): ReceiptStore => new class implements ReceiptStore
        {
            private ?Receipt $receipt = null;

            public function write(Receipt $receipt): void
            {
                $this->receipt = $receipt;
            }

            public function read(): ?Receipt
            {
                return $this->receipt;
            }
        }),
    ));
}

function declareSingle(): void
{
    $pipelines = Pipelines::single(Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test', id: 'pint'));
    }));

    app()->instance(Pipelines::class, $pipelines);

    app()->instance(RunManager::class, new RunManager(
        $pipelines,
        new StepRunnerFactory(static fn (string $name): StepRunner => new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        }),
    ));
}

beforeEach(function (): void {
    $this->configPath = base_path('.config/pipeline.php');

    if (! is_dir(dirname($this->configPath))) {
        mkdir(dirname($this->configPath), recursive: true);
    }

    file_put_contents($this->configPath, '<?php return '.Pipeline::class.'::configure();');
});

afterEach(function (): void {
    if (is_file($this->configPath)) {
        unlink($this->configPath);
    }
});

it('opens the pipeline it was asked for, and reports which one', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'release'])
        ->assertOk()
        ->assertSee('"pipeline":"release"')
        ->assertSee('audit')
        // Never a step from the pipeline that was not asked for.
        ->assertDontSee('pint');
});

it('refuses to open a run without a name when several are declared', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class)->assertHasErrors();
});

it('names what is configured when asked for a pipeline that is not', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'staging'])
        ->assertHasErrors()
        ->assertSee('staging');
});

it('refuses next_step without a name, rather than advancing a cursor it guessed', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])->assertOk();

    PipelineServer::tool(NextStep::class)->assertHasErrors();
});

it('refuses status without a name', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])->assertOk();

    PipelineServer::tool(Status::class)->assertHasErrors();
});

it('says which pipeline has no run open, rather than just that none is', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])->assertOk();

    PipelineServer::tool(NextStep::class, ['pipeline' => 'release'])
        ->assertHasErrors()
        ->assertSee('release');
});

it('walks one pipeline while another sits part-walked, and resumes it', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])->assertOk();
    PipelineServer::tool(NextStep::class, ['pipeline' => 'pr'])->assertOk();

    // A whole other pipeline, opened and finished in between.
    PipelineServer::tool(OpenRun::class, ['pipeline' => 'release'])->assertOk();
    PipelineServer::tool(NextStep::class, ['pipeline' => 'release'])->assertOk();

    // The pr cursor is where it was left, not back at the start: `pint` already
    // holds a verdict and `phpstan` is the step now being offered. A restart
    // would show pint at the cursor and no verdict at all.
    PipelineServer::tool(Status::class, ['pipeline' => 'pr'])
        ->assertOk()
        ->assertSee('"pipeline":"pr"')
        ->assertSee('pint')
        ->assertSee('phpstan')
        // And nothing from the pipeline walked in between.
        ->assertDontSee('audit');
});

it("keeps each pipeline's verdicts to itself", function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'release'])->assertOk();
    PipelineServer::tool(NextStep::class, ['pipeline' => 'release'])->assertOk();

    PipelineServer::tool(Status::class, ['pipeline' => 'release'])
        ->assertOk()
        ->assertSee('audit')
        ->assertDontSee('phpstan');
});

it('composes a pipeline name with a scope', function (): void {
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr', 'only' => 'backend'])
        ->assertOk()
        ->assertSee('"pipeline":"pr"')
        ->assertSee('"scope":"backend"');
});

it('says nothing about a pipeline when the project declares one', function (): void {
    // The envelope key and the tool argument both stay absent, so nothing changes
    // for a project that never had a choice to make.
    declareSingle();

    PipelineServer::tool(OpenRun::class)
        ->assertOk()
        ->assertSee('pint')
        ->assertDontSee('"pipeline"');
});

it('declares the pipeline argument on every tool that needs one', function (): void {
    // All four, not just open_run. A tool whose handler demands the name while
    // its schema advertises nothing leaves a conforming client no way to send it,
    // and the walk stops dead after open_run — which is exactly what happened
    // when only open_run and report_step were wired up.
    $schema = new JsonSchemaTypeFactory;

    declareMap();

    $tools = [
        'open_run' => new OpenRun(resolve(RunManager::class), resolve(CommandPreflight::class)),
        'next_step' => new NextStep(resolve(RunManager::class)),
        'report_step' => new ReportStep(resolve(RunManager::class)),
        'status' => new Status(resolve(RunManager::class)),
    ];

    $required = [];

    foreach ($tools as $name => $tool) {
        $argument = $tool->schema($schema)['pipeline'] ?? null;

        // Presence is not the contract — the argument has to be REQUIRED, or a
        // client is free to omit the one thing the handler cannot do without.
        $required[$name] = $argument instanceof Type
            && ((fn (): array => get_object_vars($argument))->call($argument)['required'] ?? null) === true;
    }

    expect($required)->toBe([
        'open_run' => true,
        'next_step' => true,
        'report_step' => true,
        'status' => true,
    ]);
});

it('declares no pipeline argument for a config that returned a bare Pipeline', function (): void {
    $schema = new JsonSchemaTypeFactory;

    declareSingle();

    $tools = [
        new OpenRun(resolve(RunManager::class), resolve(CommandPreflight::class)),
        new NextStep(resolve(RunManager::class)),
        new ReportStep(resolve(RunManager::class)),
        new Status(resolve(RunManager::class)),
    ];

    foreach ($tools as $tool) {
        expect(array_keys($tool->schema($schema)))->not->toContain('pipeline');
    }
});

it('walks a whole named pipeline through the tools, schema and all', function (): void {
    // The end-to-end shape finding a missing schema would have broken: open,
    // advance to the end, and read the result, every call carrying the name.
    declareMap();

    PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])->assertOk();
    PipelineServer::tool(NextStep::class, ['pipeline' => 'pr'])->assertOk();
    PipelineServer::tool(NextStep::class, ['pipeline' => 'pr'])->assertOk();

    PipelineServer::tool(Status::class, ['pipeline' => 'pr'])
        ->assertOk()
        ->assertSee('"state":"complete"')
        ->assertSee('"all_verified":true');
});
