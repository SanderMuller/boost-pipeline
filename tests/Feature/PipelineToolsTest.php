<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\ReportStep;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/** @var array<string, Verdict> */
$scripted = [];

beforeEach(function (): void {
    $this->verdicts = [];

    // The tools decline to register unless the project has opted in, so an
    // opted-in project is what the test has to simulate: the file's presence is
    // the opt-in; its contents are irrelevant here because the RunManager is
    // injected below.
    $this->configPath = $this->app->basePath('.config/pipeline.php');

    if (! is_dir(dirname($this->configPath))) {
        mkdir(dirname($this->configPath), recursive: true);
    }

    file_put_contents($this->configPath, '<?php return \SanderMuller\BoostPipeline\Config\Pipeline::configure();');

    $runner = new class($this) implements StepRunner
    {
        public function __construct(private object $test) {}

        public function run(Step $step): Result
        {
            return match ($this->test->verdicts[$step->id()] ?? Verdict::Passed) {
                Verdict::Failed => Result::failed($step->id(), 'problems found', 1, filesInspected: 0),
                Verdict::Error => Result::error($step->id(), 'binary missing'),
                default => Result::passed($step->id(), 'ok'),
            };
        }
    };

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('composer phpstan'));
        $steps->in(Agent::class)->append(Skill::run('/evaluate'));
    });

    $this->app->instance(RunManager::class, new RunManager($pipeline, $runner));
});

afterEach(function (): void {
    if (isset($this->configPath) && is_file($this->configPath)) {
        unlink($this->configPath);
    }
});

/**
 * Invoke report_step with a real Request.
 *
 * `PipelineServer::tool(ReportStep::class, [...])` cannot be used: laravel/mcp
 * v0.9.4 populates a resolved Request from an `mcp.request` container binding
 * that its Testing harness never sets, so arguments arrive empty. Confirmed
 * against the live stdio server that real JSON-RPC calls deliver them fine.
 */
function acknowledge(string $summary): void
{
    (new ReportStep(app(RunManager::class)))->handle(new Request(['summary' => $summary]));
}

it('reveals exactly one step and leaks no later step id', function (): void {
    PipelineServer::tool(OpenRun::class)
        ->assertOk()
        ->assertSee('pint')
        ->assertSee('Formatting')
        // total_steps is deliberately included — withholding it is theatre, since
        // the agent can count the steps in .config/pipeline.php. What must never
        // appear is the IDENTITY of a step past the cursor.
        ->assertSee('"total_steps":3')
        ->assertDontSee('phpstan')
        ->assertDontSee('evaluate');
});

it('refuses next_step before a run is open', function (): void {
    // RunManager exists but no run has been opened.
    PipelineServer::tool(NextStep::class)->assertHasErrors();
});

it('declines to register its tools at all when the project has not opted in', function (): void {
    unlink($this->configPath);

    expect(app(SanderMuller\BoostPipeline\Config\PipelineLoader::class)->exists())->toBeFalse()
        ->and((new OpenRun(app(RunManager::class)))->shouldRegister())->toBeFalse();
});

it('registers its tools when the project has opted in', function (): void {
    expect((new OpenRun(app(RunManager::class)))->shouldRegister())->toBeTrue();
});

it('resumes an already-open run rather than restarting it', function (): void {
    PipelineServer::tool(OpenRun::class);
    PipelineServer::tool(NextStep::class)->assertSee('phpstan');

    PipelineServer::tool(OpenRun::class)
        ->assertSee('phpstan')
        ->assertSee('"position":"2/3"');
});

it('returns the same step repeatedly while it fails, and blocks rather than halting', function (): void {
    $this->verdicts = ['pint' => Verdict::Failed];
    PipelineServer::tool(OpenRun::class);

    foreach (range(1, 3) as $ignored) {
        PipelineServer::tool(NextStep::class)
            ->assertOk()
            ->assertSee('"state":"blocked"')
            ->assertSee('pint')
            ->assertDontSee('phpstan');
    }
});

it('puts an error on the MCP error channel, and a failure on the normal one', function (): void {
    $this->verdicts = ['pint' => Verdict::Error];
    PipelineServer::tool(OpenRun::class);

    // The tool CALL failed, because the tool did not run.
    PipelineServer::tool(NextStep::class)->assertHasErrors();
})->skip(fn (): bool => false);

it('does not mark a failing check as an MCP error', function (): void {
    $this->verdicts = ['pint' => Verdict::Failed];
    PipelineServer::tool(OpenRun::class);

    // The call SUCCEEDED and reported a finding. Flagging isError here would make
    // every failing check look like a broken server.
    PipelineServer::tool(NextStep::class)->assertHasNoErrors();
});

it('awaits a skill step, holds until report_step, then completes', function (): void {
    PipelineServer::tool(OpenRun::class);
    PipelineServer::tool(NextStep::class);

    // Landing on a skill step enters awaiting immediately — no extra round trip.
    PipelineServer::tool(NextStep::class)
        ->assertSee('"state":"awaiting"')
        ->assertSee('evaluate')
        ->assertSee('acknowledged, not verified');

    // Calling next_step again must not advance past an unacknowledged skill step.
    PipelineServer::tool(NextStep::class)->assertSee('"state":"awaiting"');

    acknowledge('ran /evaluate');

    PipelineServer::tool(Status::class)
        ->assertOk()
        ->assertSee('"state":"complete"')
        ->assertSee('"all_verified":false');
});

it('rejects report_step for a shell step', function (): void {
    PipelineServer::tool(OpenRun::class);

    PipelineServer::tool(ReportStep::class, ['summary' => 'I ran pint myself'])
        ->assertHasErrors();
});

it('keeps server_run and acknowledged as separate keys in status', function (): void {
    $this->verdicts = ['phpstan' => Verdict::Failed];
    PipelineServer::tool(OpenRun::class);
    PipelineServer::tool(NextStep::class);
    PipelineServer::tool(NextStep::class);

    PipelineServer::tool(Status::class)
        ->assertOk()
        ->assertSee('"server_run":{"passed":1,"failed":1,"error":0}')
        ->assertSee('"acknowledged":0')
        // A failed step was still RUN by the server, so it is server_run: true.
        ->assertSee('"verdict":"failed","server_run":true')
        ->assertSee('"files_inspected":0');
});

it('walks a whole pipeline to complete and never claims a verified pass it did not earn', function (): void {
    PipelineServer::tool(OpenRun::class)->assertSee('"total_steps":3');

    PipelineServer::tool(NextStep::class)->assertSee('"verdict":"passed"');
    PipelineServer::tool(NextStep::class)->assertSee('"state":"awaiting"');
    acknowledge('ran /evaluate');

    PipelineServer::tool(Status::class)
        ->assertOk()
        ->assertSee('"state":"complete"')
        // Two shell steps verified, one acknowledged — so the run completed
        // WITHOUT being all-verified. This is the property the whole design
        // exists to protect, asserted end to end.
        ->assertSee('"all_verified":false')
        ->assertSee('"server_run":{"passed":2,"failed":0,"error":0}')
        ->assertSee('"acknowledged":1');
});

it('rejects report_step with a missing or blank summary', function (): void {
    PipelineServer::tool(OpenRun::class);

    $tool = new ReportStep(app(RunManager::class));

    // Previously `(string) $request->get('summary')` turned a missing argument
    // into '', so an acknowledgement with no content was accepted silently.
    $isError = static function (Response|ResponseFactory $result): bool {
        return $result instanceof Response
            ? $result->isError()
            : $result->responses()->contains(static fn (Response $r): bool => $r->isError());
    };

    expect($isError($tool->handle(new Request([]))))->toBeTrue()
        ->and($isError($tool->handle(new Request(['summary' => '   ']))))->toBeTrue();
});
