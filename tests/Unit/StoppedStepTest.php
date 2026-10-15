<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Process;

/**
 * A step that is stopped takes every process it started with it, and then runs
 * its cleanup. A test runner's workers sit below the shell the server starts, so
 * stopping the shell alone left them running with the databases they held.
 */
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bp-stopped-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->runner = new ProcessStepRunner(
        workingDirectory: $this->dir,
        logs: new LogWriter($this->dir.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber($this->dir),
    );
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->dir])->run();
});

/** A step whose shell starts a shell that starts a long sleep, and writes down the sleep's pid. */
function grandchildStep(string $dir, float $timeout = 30.0): Shell
{
    return Shell::run(sprintf("sh -c 'sleep 30 & echo \$! > %s/grandchild; wait'", $dir), id: 'tree')->timeout($timeout);
}

function grandchildPid(string $dir): int
{
    $deadline = microtime(true) + 5.0;

    while (! is_file($dir.'/grandchild') || trim((string) file_get_contents($dir.'/grandchild')) === '') {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The step never started its grandchild.');
        }

        Sleep::usleep(20_000);
    }

    return (int) trim((string) file_get_contents($dir.'/grandchild'));
}

/** The process state letter, or null when the process is gone. */
function processState(int $pid): ?string
{
    if (is_dir('/proc/self')) {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        $close = $stat === false ? false : strrpos($stat, ')');

        return $stat === false || $close === false ? null : substr($stat, $close + 2, 1);
    }

    $ps = new Process(['ps', '-o', 'stat=', '-p', (string) $pid]);
    $ps->run();

    // ps exits 1 for a pid it cannot find. Anything else is ps failing, which
    // must not read as the process being gone.
    if (! in_array($ps->getExitCode(), [0, 1], true)) {
        throw new RuntimeException('ps failed: '.$ps->getErrorOutput());
    }

    $state = trim($ps->getOutput());

    return $state === '' ? null : $state[0];
}

/** Gone, or a zombie its new parent has not reaped yet, which runs nothing. */
function settlesGone(int $pid): bool
{
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline) {
        $state = processState($pid);

        if ($state === null || $state === 'Z') {
            return true;
        }

        Sleep::usleep(50_000);
    }

    return false;
}

it('stops the processes a step started when a blocking step times out', function (): void {
    $result = $this->runner->run(grandchildStep($this->dir, 1.0), 'r-test');

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toStartWith('Timed out after 1s.')
        ->and(settlesGone(grandchildPid($this->dir)))->toBeTrue();
})->skipOnWindows();

it('stops the processes a step started when a background step times out', function (): void {
    $results = $this->runner->begin([grandchildStep($this->dir, 1.0)], 'r-test')->await(INF) ?? [];

    expect($results['tree']->reason ?? null)->toStartWith('Timed out after 1s.')
        ->and(settlesGone(grandchildPid($this->dir)))->toBeTrue();
})->skipOnWindows();

it('stops the processes a step started when its run is abandoned, and runs its onAbort command', function (): void {
    $step = grandchildStep($this->dir)->onAbort('echo cleaned > '.$this->dir.'/marker');
    $pending = $this->runner->begin([$step], 'r-test');
    $pid = grandchildPid($this->dir);

    expect($pending->await(0.1))->toBeNull();

    $pending->stop();

    expect(settlesGone($pid))->toBeTrue()
        ->and(trim((string) @file_get_contents($this->dir.'/marker')))->toBe('cleaned');
})->skipOnWindows();

it('runs the onAbort command after a timeout and says how it went, without changing the verdict', function (): void {
    $step = Shell::run('sleep 30', id: 'slow')->timeout(0.5)->onAbort('echo cleaned; echo cleaned > '.$this->dir.'/marker');
    $result = $this->runner->run($step, 'r-test');

    preg_match('/Ran its onAbort command: exit 0, output at (\S+\.log)\.$/', $result->summary, $log);

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toStartWith('Timed out after 0.5s.')
        ->and(trim((string) file_get_contents($this->dir.'/marker')))->toBe('cleaned')
        ->and(trim((string) file_get_contents($log[1] ?? '')))->toBe('cleaned')
        ->and($log[1] ?? '')->not->toBe($result->logPath);
});

it('does not run the onAbort command for a step that ended on its own', function (string $command, Verdict $verdict): void {
    $step = Shell::run($command, id: 'ends')->onAbort('echo cleaned > '.$this->dir.'/marker');

    expect($this->runner->run($step, 'r-test')->verdict)->toBe($verdict)
        ->and($this->dir.'/marker')->not->toBeFile();

    $pending = $this->runner->begin([$step], 'r-test');
    $pending->await(INF);
    $pending->stop();

    expect($this->dir.'/marker')->not->toBeFile();
})->with([
    'a pass' => ['true', Verdict::Passed],
    'a failure' => ['false', Verdict::Failed],
]);

it('refuses an empty onAbort command', function (): void {
    expect(fn (): Shell => Shell::run('true')->onAbort('  '))
        ->toThrow(InvalidPipelineConfigException::class, 'empty onAbort()');
});

it('times a blocking step that timed out up to its stop, not through its onAbort command', function (): void {
    $step = Shell::run('sleep 30', id: 'slow')->timeout(0.3)->onAbort('sleep 1');
    $result = $this->runner->run($step, 'r-test');

    expect($result->durationMs)->toBeGreaterThanOrEqual(300)
        ->and($result->durationMs)->toBeLessThan(1000);
});
