<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Process;

/**
 * These run real processes, because the claim is about wall clock and about every
 * sibling producing a verdict. A fake runner would prove neither.
 */
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bp-parallel-'.bin2hex(random_bytes(4));
    mkdir($this->dir);

    $this->runner = new ProcessStepRunner(
        workingDirectory: $this->dir,
        logs: new LogWriter($this->dir.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber($this->dir),
        timeoutSeconds: 30.0,
    );
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        new Process(['rm', '-rf', $this->dir])->run();
    }
});

function runParallel(Closure $group, StepRunner $runner): Run
{
    return Run::start(
        Pipeline::configure()
            ->withSteps(function (Steps $steps) use ($group): void {
                $steps->in(StaticAnalysis::class)->parallel($group);
            })
            ->walk(),
        $runner,
        'r-parallel',
    );
}

it('runs the group at the same time, not one after another', function (): void {
    $run = runParallel(function (StepCollection $steps): void {
        $steps->append(Shell::run('sleep 1', id: 'a'));
        $steps->append(Shell::run('sleep 1', id: 'b'));
        $steps->append(Shell::run('sleep 1', id: 'c'));
    }, $this->runner);

    $start = hrtime(true);
    $results = $run->resolveCurrent();
    $elapsed = (hrtime(true) - $start) / 1_000_000_000;

    // Sequentially this is 3s. Concurrently it is a shade over 1s. The margin is
    // wide enough that a slow machine does not make this flaky, and still far
    // below the sequential figure.
    expect($results)->toHaveCount(3)
        ->and($elapsed)->toBeLessThan(2.5)
        ->and($run->state())->toBe(RunState::Complete);
});

it('reports every failure in the group, not just the first', function (): void {
    // The reason to group beyond speed. A sequence blocks at the first failure and
    // hides the rest behind a fix and a re-run.
    $run = runParallel(function (StepCollection $steps): void {
        $steps->append(Shell::run('echo one >&2; exit 1', id: 'a'));
        $steps->append(Shell::run('true', id: 'b'));
        $steps->append(Shell::run('echo three >&2; exit 1', id: 'c'));
    }, $this->runner);

    $run->resolveCurrent();

    expect($run->resultFor('a')?->verdict)->toBe(Verdict::Failed)
        ->and($run->resultFor('b')?->verdict)->toBe(Verdict::Passed)
        ->and($run->resultFor('c')?->verdict)->toBe(Verdict::Failed)
        ->and($run->state())->toBe(RunState::Blocked);
});

it('holds the position when one sibling fails, and re-runs the whole group', function (): void {
    $run = runParallel(function (StepCollection $steps): void {
        $steps->append(Shell::run('true', id: 'a'));
        $steps->append(Shell::run('test -f fixed.txt', id: 'b'));
    }, $this->runner);

    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Blocked)
        ->and($run->currentPosition())->toHaveCount(2)
        ->and($run->allVerified())->toBeFalse();

    touch($this->dir.'/fixed.txt');
    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Complete)
        ->and($run->allVerified())->toBeTrue();
});

it('halts the group when a sibling could not run at all', function (): void {
    // An error outranks a failure: a tool that never ran is the more urgent
    // report, and it decides the state for the position.
    $run = runParallel(function (StepCollection $steps): void {
        $steps->append(Shell::run('exit 1', id: 'failed'));
        $steps->append(Shell::run('definitely-not-a-real-binary-xyz', id: 'missing'));
    }, $this->runner);

    $run->resolveCurrent();

    expect($run->resultFor('missing')?->verdict)->toBe(Verdict::Error)
        ->and($run->resultFor('failed')?->verdict)->toBe(Verdict::Failed)
        ->and($run->state())->toBe(RunState::Halted);
});

it('writes a log per step in the group', function (): void {
    $run = runParallel(function (StepCollection $steps): void {
        $steps->append(Shell::run('echo from-a', id: 'a'));
        $steps->append(Shell::run('echo from-b', id: 'b'));
    }, $this->runner);

    $run->resolveCurrent();

    $a = $run->resultFor('a')?->logPath;
    $b = $run->resultFor('b')?->logPath;

    expect($a)->not->toBe($b)
        ->and(file_get_contents((string) $a))->toContain('from-a')
        ->and(file_get_contents((string) $b))->toContain('from-b');
});

it('lets a step in the group keep its own environment and timeout', function (): void {
    $run = runParallel(function (StepCollection $steps): void {
        $steps->append(Shell::run('printf "%s" "$PINNED"', id: 'pinned')->withEnv(['PINNED' => 'yes']));
        $steps->append(Shell::run('sleep 5', id: 'slow')->timeout(0.5));
    }, $this->runner);

    $run->resolveCurrent();

    expect($run->resultFor('pinned')?->summary)->toContain('yes')
        ->and($run->resultFor('slow')?->verdict)->toBe(Verdict::Error)
        ->and($run->resultFor('slow')?->summary)->toContain('Timed out');
});

it('still resolves a lone step one at a time', function (): void {
    $run = Run::start(
        Pipeline::configure()
            ->withSteps(function (Steps $steps): void {
                $steps->in(Formatting::class)->append(Shell::run('true', id: 'lone'));
                $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'other'));
            })
            ->walk(),
        $this->runner,
        'r-lone',
    );

    expect($run->resolveCurrent())->toHaveCount(1)
        ->and($run->resolveCurrent())->toHaveCount(1)
        ->and($run->state())->toBe(RunState::Complete);
});

it('resolves a group one step at a time for a runner that cannot batch', function (): void {
    // The compatibility promise: a custom StepRunner keeps working. Its group is
    // slower, and every verdict is still recorded against the one position.
    $sequential = new class implements StepRunner
    {
        /** @var list<string> */
        public array $order = [];

        public function run(Step $step, string $runId): Result
        {
            $this->order[] = $step->id();

            return Result::passed($step->id(), 'ok');
        }
    };

    $run = runParallel(function (StepCollection $steps): void {
        $steps->append(Shell::run('true', id: 'a'));
        $steps->append(Shell::run('true', id: 'b'));
    }, $sequential);

    expect($run->resolveCurrent())->toHaveCount(2)
        ->and($sequential->order)->toBe(['a', 'b'])
        ->and($run->state())->toBe(RunState::Complete)
        ->and($run->allVerified())->toBeTrue();
});
