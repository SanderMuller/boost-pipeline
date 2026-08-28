<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Prompts\RunPipeline;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * A halt says the tool could not run — a missing binary, a bad path. That is
 * precisely the kind of thing the agent then fixes, so refusing forever left the
 * only way forward as restarting the server, which in Claude Code means
 * restarting the session.
 */
final class BinaryInstalledOnSecondTry implements StepRunner
{
    public int $attempts = 0;

    public function run(Step $step, string $runId): Result
    {
        $this->attempts++;

        return $this->attempts === 1
            ? Result::error($step->id(), 'binary missing')
            : Result::passed($step->id(), 'ok');
    }
}

beforeEach(function (): void {
    $this->configPath = app()->basePath('.config/pipeline.php');

    if (! is_dir(dirname($this->configPath))) {
        mkdir(dirname($this->configPath), recursive: true);
    }

    file_put_contents($this->configPath, '<?php return '.Pipeline::class.'::configure();');

    $this->runner = new BinaryInstalledOnSecondTry;

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
    });

    app()->instance(RunManager::class, runManagerFor($pipeline, $this->runner));
});

afterEach(function (): void {
    if (is_file($this->configPath)) {
        unlink($this->configPath);
    }
});

it('retries the halted step instead of refusing for the rest of the session', function (): void {
    PipelineServer::tool(OpenRun::class);

    PipelineServer::tool(NextStep::class)
        ->assertHasErrors()
        // The cursor has not moved, so the instruction has to say the step is
        // retryable — the old wording told the agent the opposite.
        ->assertSee('fix what stopped it')
        // And which call to make. Not a judgement the reader has to get right:
        // open_run is idempotent on an unmoved tree, so it is the safe call
        // whether or not the fix touched anything.
        ->assertSee('hands back this same run when nothing moved');

    PipelineServer::tool(NextStep::class)
        ->assertOk()
        ->assertSee('"state":"complete"')
        ->assertSee('"all_verified":true');

    expect($this->runner->attempts)->toBe(2);
});

it('answers whether the run can be trusted while it is still halted', function (): void {
    // `halted` and `blocked` are retryable, so a run sits there while the agent
    // decides. That is exactly when a consumer asks whether the run is any good,
    // and the key used to be absent until the walk finished — leaving "absent"
    // and "false" to be told apart.
    PipelineServer::tool(OpenRun::class);
    PipelineServer::tool(NextStep::class)->assertHasErrors();

    PipelineServer::tool(Status::class)
        ->assertOk()
        ->assertSee('"state":"halted"')
        ->assertSee('"all_verified":false');
});

it('names the log path when a halted step wrote one', function (): void {
    // The state where the output matters most was the one naming no way to reach
    // it: an `error` verdict takes MCP's text-only error channel, while `failed`
    // goes through `Response::structured()` and carries `log`. Truncation made it
    // worse — the summary said how many lines were dropped and then dropped them.
    $runner = new class implements StepRunner
    {
        public function run(Step $step, string $runId): Result
        {
            return Result::error(
                $step->id(),
                "Timed out after 3s. 1\n… 380 lines omitted …\n400",
                logPath: '/tmp/pipeline/r-abc123-noisy-slow.log',
            );
        }
    };

    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('seq 1 400; sleep 30', id: 'noisy-slow'));
    });

    app()->instance(RunManager::class, runManagerFor($pipeline, $runner));

    PipelineServer::tool(OpenRun::class);

    PipelineServer::tool(NextStep::class)
        ->assertHasErrors()
        ->assertSee('380 lines omitted')
        ->assertSee('Full output: /tmp/pipeline/r-abc123-noisy-slow.log');
});

it('says nothing about a log when the step wrote none', function (): void {
    // The existing harness errors without a log path, so the clause has to be
    // conditional rather than printing an empty pointer.
    PipelineServer::tool(OpenRun::class);

    PipelineServer::tool(NextStep::class)
        ->assertHasErrors()
        ->assertDontSee('Full output:');
});

it('tells a blocked run to reopen rather than continue, on every surface that advises', function (): void {
    // The fix loop's advice used to end a walk that could never verify: fixing a
    // failing check edits a file, the tree moves, the steps that already passed
    // are stale, and `next_step` never re-checks that — only `open_run` does.
    // Three surfaces carried the old advice and an agent reads whichever it sees,
    // so asserting one would leave the other two free to drift back.
    //
    // The rule is deliberately not "reopen if your fix changed a file". A commit
    // moves the fingerprint with no file changed, so that version contradicted
    // the stale message. `open_run` is idempotent on an unmoved tree, which lets
    // the advice remove the judgement instead of trying to state it.
    //
    // Each phrase is specific to the new wording. `open_run` alone would pass
    // vacuously: the prompt's first step already tells the agent to call it.
    $tool = new NextStep(resolve(RunManager::class));

    $description = new ReflectionClass($tool)->getProperty('description')->getValue($tool);
    $instructions = new ReflectionClass(PipelineServer::class)->getDefaultProperties()['instructions'];

    $advises = [
        'tool description' => is_string($description)
            && str_contains($description, 'call open_run rather than this'),
        'server instructions' => is_string($instructions)
            && str_contains($instructions, 'do not try to work out whether your fix'),
    ];

    expect($advises)->toBe([
        'tool description' => true,
        'server instructions' => true,
    ]);

    PipelineServer::prompt(RunPipeline::class)
        ->assertOk()
        ->assertSee('Do not work out whether your fix moved the tree');
});

it('never tells the reader that no file changed means next_step is safe', function (): void {
    // The rule this replaced said exactly that, and it contradicted the stale
    // message shipped two releases earlier: the fingerprint covers the commit, so
    // an amend moves the tree with nothing on disk changed. A rule the reader has
    // to apply is a rule they can apply wrongly; `open_run` being idempotent on an
    // unmoved tree is what lets the advice drop the judgement entirely.
    $tool = new NextStep(resolve(RunManager::class));
    $description = new ReflectionClass($tool)->getProperty('description')->getValue($tool);
    $instructions = new ReflectionClass(PipelineServer::class)->getDefaultProperties()['instructions'];

    $carriesOldRule = [
        'tool description' => ! is_string($description)
            || str_contains($description, 'nothing on disk changed'),
        'server instructions' => ! is_string($instructions)
            || str_contains($instructions, 'nothing on disk changed'),
    ];

    expect($carriesOldRule)->toBe([
        'tool description' => false,
        'server instructions' => false,
    ])
        // And a surface names a commit as moving the tree, so a reader cannot
        // conclude that an amend leaves the run intact.
        ->and(is_string($instructions) && str_contains($instructions, 'amend'))->toBeTrue();

    PipelineServer::prompt(RunPipeline::class)->assertOk()->assertSee('amend');
});
