<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
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

    app()->instance(RunManager::class, new RunManager($pipeline, $this->runner));
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
        ->assertSee('fix what stopped it and call next_step again');

    PipelineServer::tool(NextStep::class)
        ->assertOk()
        ->assertSee('"state":"complete"')
        ->assertSee('"all_verified":true');

    expect($this->runner->attempts)->toBe(2);
});
