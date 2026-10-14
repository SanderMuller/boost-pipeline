<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\BackgroundStepRunner;
use SanderMuller\BoostPipeline\Contracts\PendingSteps;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\AcknowledgementNotAllowed;
use SanderMuller\BoostPipeline\Run\JsonLiveProgressStore;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;
use Symfony\Component\Process\Process;

/**
 * The stdio transport reads one request at a time, so `status` cannot answer
 * while `next_step` waits on a step. A response window makes `next_step` return
 * while the step keeps running. Everything below pins that only the moment the
 * verdict is collected changes — never the verdict, the cursor, or staleness.
 */
final class WindowTree implements TreeFingerprint
{
    public string $digest = 'tree-a';

    public function capture(): string
    {
        return $this->digest;
    }
}

function backgroundRunner(string $logs): ProcessStepRunner
{
    return new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($logs),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 20.0,
    );
}

function slowPipeline(string $command = 'sleep 0.6; echo finished', ?float $window = 0.05): Pipeline
{
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps) use ($command): void {
        $steps->in(Tests::class)->append(Shell::run($command, id: 'suite'));
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
    });

    return $window === null ? $pipeline : $pipeline->withResponseWindow($window);
}

/**
 * Call resolveCurrent until the position settles, the way an agent calls next_step.
 *
 * @return list<Result>
 */
function resolveUntilSettled(Run $run): array
{
    for ($attempt = 0; $attempt < 200; $attempt++) {
        $results = $run->resolveCurrent();

        if (! $run->isPending()) {
            return $results;
        }
    }

    throw new RuntimeException('The position never settled.');
}

beforeEach(function (): void {
    $this->logs = sys_get_temp_dir().'/bp-window-'.bin2hex(random_bytes(4));
    $this->tree = new WindowTree;
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->logs])->run();
});

it('refuses a response window that is not positive', function (): void {
    Pipeline::configure()->withResponseWindow(0.0);
})->throws(InvalidPipelineConfigException::class);

it('is off by default, so a resolution still blocks until its verdict', function (): void {
    $run = runManagerFor(slowPipeline(window: null), backgroundRunner($this->logs))->open();

    $results = $run->resolveCurrent();

    expect($run->isPending())->toBeFalse()
        ->and($results[0]->verdict)->toBe(Verdict::Passed);
});

it('returns before the step finishes, holds the cursor, then records the same verdict', function (): void {
    $run = runManagerFor(slowPipeline(), backgroundRunner($this->logs), $this->tree)->open();

    expect($run->resolveCurrent())
        ->toBeEmpty()
        ->and($run->isPending())->toBeTrue()
        ->and($run->state())->toBe(RunState::Running)
        ->and($run->currentStep()?->step->id())->toBe('suite')
        ->and($run->results())
        ->toBeEmpty();

    $results = resolveUntilSettled($run);

    expect($results[0]->verdict)->toBe(Verdict::Passed)
        ->and($results[0]->summary)->toContain('finished')
        ->and($run->state())->toBe(RunState::Awaiting)
        ->and($run->currentStep()?->step->id())->toBe('review')
        ->and($run->staleReason())->toBeNull();
});

it('goes stale when the tree moves while the step runs, as a blocking call would', function (): void {
    $run = runManagerFor(slowPipeline(), backgroundRunner($this->logs), $this->tree)->open();

    $run->resolveCurrent();

    $this->tree->digest = 'tree-b';

    resolveUntilSettled($run);

    expect($run->staleReason())->toContain('[suite]')
        ->and($run->verification()['all_verified'])->toBeFalse();
});

it('refuses an acknowledgement while a step is still running', function (): void {
    $run = runManagerFor(slowPipeline(), backgroundRunner($this->logs))->open();

    $run->resolveCurrent();

    expect(fn (): mixed => $run->acknowledgeCurrentStep('done'))->toThrow(AcknowledgementNotAllowed::class);
});

it('hands back the same run on a reopen while its step runs, even after an edit', function (): void {
    $runs = runManagerFor(slowPipeline(), backgroundRunner($this->logs), $this->tree);
    $run = $runs->open();

    $run->resolveCurrent();

    $this->tree->digest = 'tree-b';

    expect($runs->open())->toBe($run)
        ->and($run->isPending())->toBeTrue();
});

it('stops the running step when a reopen replaces the run', function (): void {
    $marker = sys_get_temp_dir().'/bp-orphan-'.bin2hex(random_bytes(4));
    $runs = runManagerFor(slowPipeline('sleep 1; touch '.escapeshellarg($marker)), backgroundRunner($this->logs));
    $run = $runs->open();

    $run->resolveCurrent();

    // A different scope asks a different question, so the run is replaced.
    $replacement = $runs->open(selection: 'backend');
    Sleep::usleep(1_500_000);

    expect($replacement)->not->toBe($run)
        ->and($run->isPending())->toBeFalse()
        ->and(file_exists($marker))->toBeFalse();
});

it('keeps the live record while the step runs and clears it once the verdict is in', function (): void {
    $store = new JsonLiveProgressStore($this->logs.'/live.json');
    $run = Run::start(slowPipeline('sleep 0.6')->walk(), backgroundRunner($this->logs), live: $store, responseWindow: 0.05);

    $run->resolveCurrent();

    expect($store->read()?->state)->toBe(RunState::Running)
        ->and($store->read()?->stepIds)->toBe(['suite']);

    resolveUntilSettled($run);

    // The successor is a skill step, so the record now says the run awaits it.
    expect($store->read()?->state)->toBe(RunState::Awaiting);
});

it('answers next_step with pending and no result, and status with how long it has run', function (): void {
    $configPath = app()->basePath('.config/pipeline.php');
    @mkdir(dirname($configPath), recursive: true);
    file_put_contents($configPath, '<?php return '.Pipeline::class.'::configure();');

    app()->instance(RunManager::class, runManagerFor(slowPipeline('sleep 1'), backgroundRunner($this->logs)));

    try {
        PipelineServer::tool(OpenRun::class)->assertOk();

        PipelineServer::tool(NextStep::class)
            ->assertOk()
            ->assertSee('"state":"running"')
            ->assertSee('"pending"')
            ->assertSee('"running_for_seconds"')
            ->assertDontSee('"result"');

        PipelineServer::tool(Status::class)
            ->assertOk()
            ->assertSee('"pending"');
    } finally {
        resolve(RunManager::class)->for()?->abandon();
        @unlink($configPath);
    }
});

it('stops the steps and drops the pending position when collecting them throws', function (): void {
    $pending = new class implements PendingSteps
    {
        public bool $stopped = false;

        public function await(float $seconds): ?array
        {
            throw new RuntimeException('runner broke');
        }

        public function stop(): void
        {
            $this->stopped = true;
        }
    };

    $runner = new readonly class($pending) implements BackgroundStepRunner
    {
        public function __construct(private PendingSteps $pending) {}

        public function begin(array $steps, string $runId): PendingSteps
        {
            return $this->pending;
        }

        public function run(Step $step, string $runId): Result
        {
            return Result::passed($step->id(), 'ok');
        }
    };

    $store = new JsonLiveProgressStore($this->logs.'/live.json');
    $run = Run::start(slowPipeline()->walk(), $runner, live: $store, responseWindow: 0.05);

    expect(fn (): array => $run->resolveCurrent())->toThrow(RuntimeException::class, 'runner broke')
        ->and($pending->stopped)->toBeTrue()
        ->and($run->isPending())->toBeFalse()
        ->and($store->read())->toBeNull();
});
