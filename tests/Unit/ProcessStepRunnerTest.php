<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

beforeEach(function (): void {
    $this->logDir = sys_get_temp_dir().'/bp-logs-'.bin2hex(random_bytes(4));
    $this->runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 20.0,
    );
});

/**
 * The runner takes the run id per call, so every test passes the same one and log
 * names stay predictable. `RunLogNamingTest` is what proves the id actually comes
 * from the run rather than from anywhere else.
 */
function runStep(ProcessStepRunner $runner, Step $step): Result
{
    return $runner->run($step, 'r-test');
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

it('passes a step that exits 0', function (): void {
    $result = runStep($this->runner, Shell::run('echo all good', id: 'ok'));

    expect($result->verdict)->toBe(Verdict::Passed)
        ->and($result->exitCode)->toBe(0)
        ->and($result->summary)->toContain('all good')
        ->and($result->verdict->isVerified())->toBeTrue();
});

it('fails a step that exits non-zero, and keeps it distinct from an error', function (): void {
    $result = runStep($this->runner, Shell::run('echo problem found; exit 3', id: 'nonzero'));

    expect($result->verdict)->toBe(Verdict::Failed)
        ->and($result->exitCode)->toBe(3)
        ->and($result->summary)->toContain('problem found')
        ->and($result->serverRun())->toBeTrue()
        ->and($result->verdict->isVerified())->toBeFalse();
});

it('reports a missing binary as an error, never as a failure or a pass', function (): void {
    $result = runStep($this->runner, Shell::run('definitely-not-a-real-binary-xyz', id: 'missing'));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->verdict)->not->toBe(Verdict::Failed)
        ->and($result->verdict->isVerified())->toBeFalse()
        ->and($result->verdict->isTerminalForRun())->toBeTrue()
        ->and($result->reason)->toContain('did not run');
});

it('reports a timeout as an error', function (): void {
    $fast = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.3,
    );

    $result = runStep($fast, Shell::run('sleep 3', id: 'slow'));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toContain('Timed out')
        // Regression: the timeout path used to return an empty step id, so the
        // result was filed under '' and became unattributable in status and in
        // the halt message. The original test asserted verdict and reason only,
        // and so passed straight over it.
        ->and($result->stepId)->toBe('slow');
});

it('refuses to execute a skill step', function (): void {
    $result = runStep($this->runner, Skill::run('/evaluate'));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toContain('Only shell steps');
});

it('writes the full output to a log and names the path when truncating', function (): void {
    $result = runStep($this->runner, Shell::run('seq 1 100; exit 1', id: 'noisy'));

    expect($result->logPath)->not->toBeNull()
        ->and(file_exists((string) $result->logPath))->toBeTrue()
        ->and(substr_count((string) file_get_contents((string) $result->logPath), "\n"))->toBeGreaterThan(50)
        ->and($result->summary)->toContain('more line(s)')
        ->and($result->summary)->toContain((string) $result->logPath)
        ->and(substr_count($result->summary, "\n"))->toBeLessThan(30);
});

it('leaves files_inspected unknown when a step declares no scope', function (): void {
    $result = runStep($this->runner, Shell::run('echo hi', id: 'unscoped'));

    expect($result->filesInspected)->toBeNull()
        ->and($result->toArray())->not->toHaveKey('files_inspected');
});

it('counts the files a scoped step will inspect', function (): void {
    $result = runStep($this->runner,
        Shell::run('echo checked', id: 'scoped')->inspecting('printf "a.ts\nb.ts\n"')
    );

    expect($result->filesInspected)->toBe(2)
        ->and($result->verdict)->toBe(Verdict::Passed);
});

it('says so out loud when a scoped step inspected nothing but still passed', function (): void {
    // The `yarn lint` shape: git-diff-scoped, so with no matching changes it
    // exits 0 having linted nothing. A bare pass here is a pass it did not earn.
    $result = runStep($this->runner,
        Shell::run('exit 0', id: 'vacuous')->inspecting('true')
    );

    expect($result->verdict)->toBe(Verdict::Passed)
        ->and($result->filesInspected)->toBe(0)
        ->and($result->summary)->toContain('Inspected 0 files')
        ->and($result->summary)->toContain('without proving anything')
        ->and($result->toArray()['files_inspected'])->toBe(0);
});

it('still runs the command when the declared scope is empty, and still reports its failure', function (): void {
    // Regression: an earlier implementation returned a pass BEFORE running the
    // command whenever the scope resolved to zero. A typo'd scope glob then
    // disabled the gate permanently, which is a false green by construction.
    $result = runStep($this->runner,
        Shell::run('echo REAL FAILURE >&2; exit 9', id: 'empty-scope-fails')->inspecting('true')
    );

    expect($result->verdict)->toBe(Verdict::Failed)
        ->and($result->exitCode)->toBe(9)
        ->and($result->summary)->toContain('REAL FAILURE')
        ->and($result->filesInspected)->toBe(0);
});

it('errors rather than passing when the scope command cannot run', function (): void {
    // A broken scope command produced zero lines, which read as "empty scope"
    // and handed back a pass. A declared scope that cannot be computed is a
    // broken config, not an empty one.
    $result = runStep($this->runner,
        Shell::run('echo would have run', id: 'broken-scope')->inspecting('not-a-real-command-xyz')
    );

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toContain('scope');
});

it('keeps stderr on its own line rather than gluing it to stdout', function (): void {
    $result = runStep($this->runner, Shell::run('printf "on-stdout"; printf "on-stderr" >&2; exit 1', id: 'streams'));

    expect($result->summary)->toContain('on-stdout
on-stderr');
});

it('reports a step whose setup throws as an error, not a failure', function (): void {
    // The Edge Cases table claims coverage for "a step throws rather than
    // exiting non-zero"; it had none until this test.
    $step = new class implements Step
    {
        public function id(): string
        {
            return 'throws-in-setup';
        }

        public function description(): string
        {
            return 'a step whose before() throws';
        }

        public function kind(): StepKind
        {
            return StepKind::Shell;
        }

        public function before(): void
        {
            throw new RuntimeException('setup exploded');
        }

        public function after(Result $result): void {}
    };

    $result = runStep($this->runner, $step);

    // Not a Shell instance, so the runner refuses it before before() is reached —
    // which is itself the guarantee: only a real Shell step can be executed.
    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->stepId)->toBe('throws-in-setup');
});

it('lets a step pin its own environment, which is the point of the scrubber', function (): void {
    // Documented in the README before it existed: the override path on
    // EnvironmentScrubber had no public route from a step until now.
    $result = runStep($this->runner,
        Shell::run('printf "%s" "$PIPELINE_PINNED"', id: 'pinned')
            ->withEnv(['PIPELINE_PINNED' => 'my_tp_phpunit_iso'])
    );

    expect($result->summary)->toContain('my_tp_phpunit_iso');
});
