<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * Regression: the run reported one id while its logs were named after another.
 *
 * The provider used to mint a second id of its own and hand it to the runner, so
 * every log file was named after an id no response ever mentioned — the id in the
 * payload could not be used to find the run's logs. Worse, that id was generated
 * once per process rather than once per run, so a second run in one process would
 * have overwritten the first run's logs step for step.
 *
 * These tests go through a real ProcessStepRunner on purpose. A fake runner writes
 * no logs, so it cannot see this class of defect at all.
 */
beforeEach(function (): void {
    $this->logDir = sys_get_temp_dir().'/bp-run-logs-'.bin2hex(random_bytes(4));

    // One runner, shared, because that is what the service provider registers:
    // a singleton every run in the process goes through.
    $this->runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 20.0,
    );

    $this->manager = new RunManager(pipelineEchoing('formatted'), $this->runner);
});

function pipelineEchoing(string $word): Pipeline
{
    return Pipeline::configure()->withSteps(function (Steps $steps) use ($word): void {
        $steps->in(Formatting::class)->append(Shell::run("echo {$word}", id: 'echo'));
    });
}

afterEach(function (): void {
    if (! is_dir($this->logDir)) {
        return;
    }

    $logs = glob($this->logDir.'/*.log');

    foreach ($logs === false ? [] : $logs as $file) {
        unlink($file);
    }

    rmdir($this->logDir);
});

it('names a log file after the run id it reports, so the two can be correlated', function (): void {
    $run = $this->manager->open();
    $result = $run->resolveCurrentStep();

    expect($result?->logPath)->not->toBeNull()
        ->and(basename((string) $result?->logPath))->toBe("{$run->id}-echo.log");
});

it('scopes logs per run, so a second run cannot overwrite the first one', function (): void {
    $first = $this->manager->open();
    $firstLog = $first->resolveCurrentStep()?->logPath;

    // A second run through the SAME runner, with the same step id. That is the
    // arrangement that used to collide: one id held by the runner, reused for
    // every run in the process.
    $second = new RunManager(pipelineEchoing('again'), $this->runner)->open();

    $secondLog = $second->resolveCurrentStep()?->logPath;

    expect($second->id)->not->toBe($first->id)
        ->and($secondLog)->not->toBe($firstLog)
        // The first run's log still holds the first run's output. Before the fix
        // both runs wrote to the same filename and this content was replaced.
        ->and(file_get_contents((string) $firstLog))->toContain('formatted')
        ->and(file_get_contents((string) $firstLog))->not->toContain('again')
        ->and(file_get_contents((string) $secondLog))->toContain('again');
});
