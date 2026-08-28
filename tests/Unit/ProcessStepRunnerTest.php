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

it('refuses a step that claims to be a shell step but is not one', function (): void {
    // The kind alone does not make a step runnable. Only a real Shell carries a
    // command, so anything else reaching the runner is a config fault, and an
    // error says the step did not run rather than that it found nothing.
    $step = new class implements Step
    {
        public function id(): string
        {
            return 'not-really-shell';
        }

        public function description(): string
        {
            return 'a step reporting Shell kind without being one';
        }

        public function kind(): StepKind
        {
            return StepKind::Shell;
        }

        public function mutates(): bool
        {
            return false;
        }

        /** @return list<string> */
        public function tags(): array
        {
            return [];
        }
    };

    $result = runStep($this->runner, $step);

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->stepId)->toBe('not-really-shell')
        ->and($result->summary)->toContain('Only shell steps');
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

it('logs the full scope output and bounds what reaches the payload', function (): void {
    $result = runStep($this->runner,
        Shell::run('echo would have run', id: 'noisy-scope')->inspecting('seq 1 100; exit 4')
    );

    $logs = glob($this->logDir.'/*.log');

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toContain('scope')
        ->and($result->logPath)->not->toBeNull()
        ->and(substr_count((string) file_get_contents((string) $result->logPath), "\n"))->toBeGreaterThan(50)
        ->and(substr_count((string) $result->reason, "\n"))->toBeLessThan(30)
        // The log is right there, so the reason must not claim it was lost —
        // otherwise the two halves of one result contradict each other.
        ->and($result->reason)->not->toContain('No log could be written')
        // A scope failure short-circuits before the step runs, so nothing else
        // claims `<run>-<step>.log` for this step.
        ->and($logs)->toHaveCount(1);
});

it('keeps the output a timed-out step had already printed', function (): void {
    // A timeout is when the output matters most: it shows where the command
    // stalled. The original implementation threw all of it away.
    $fast = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.5,
    );

    $result = runStep($fast, Shell::run('seq 1 100; sleep 3', id: 'noisy-timeout'));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toStartWith('Timed out')
        ->and($result->logPath)->not->toBeNull()
        ->and(substr_count((string) file_get_contents((string) $result->logPath), "\n"))->toBeGreaterThan(50)
        ->and(substr_count((string) $result->reason, "\n"))->toBeLessThan(30);
});

it('says so rather than inventing output when a timed-out step printed nothing', function (): void {
    $fast = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.3,
    );

    $result = runStep($fast, Shell::run('sleep 3', id: 'silent-timeout'));

    expect($result->reason)->toBe('Timed out after 0.3s. no output');
});

it('keeps the output of a step killed by a signal', function (): void {
    $result = runStep($this->runner, Shell::run('seq 1 100; kill -9 $$', id: 'signalled'));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toStartWith('Could not run:')
        ->and($result->logPath)->not->toBeNull()
        ->and(substr_count((string) file_get_contents((string) $result->logPath), "\n"))->toBeGreaterThan(50)
        ->and(substr_count((string) $result->reason, "\n"))->toBeLessThan(30);
});

it('keeps the output of a scope command killed by a signal, and names its log', function (): void {
    // The synchronous path. `process()` discarded the Process in both catches,
    // and `resolveScope()` then dropped the log path on the floor.
    $result = runStep($this->runner,
        Shell::run('echo would have run', id: 'signalled-scope')->inspecting('seq 1 100; kill -9 $$')
    );

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toContain('scope')
        ->and($result->logPath)->not->toBeNull()
        ->and(substr_count((string) file_get_contents((string) $result->logPath), "\n"))->toBeGreaterThan(50)
        ->and(substr_count((string) $result->reason, "\n"))->toBeLessThan(30);
});

it('bounds the payload of a command that could not run, however much it printed', function (): void {
    // Exit 127 already wrote its log; it was the payload that stayed unbounded.
    $result = runStep($this->runner, Shell::run('seq 1 100; definitely-not-a-real-binary-xyz', id: 'noisy-127'));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toStartWith('Command did not run (exit 127):')
        ->and($result->logPath)->not->toBeNull()
        ->and(substr_count((string) file_get_contents((string) $result->logPath), "\n"))->toBeGreaterThan(50)
        ->and(substr_count((string) $result->reason, "\n"))->toBeLessThan(30);
});

it('still returns an error verdict when the log cannot be written', function (): void {
    // A log directory that cannot exist: its parent is a file. Losing the log
    // must not turn a real error verdict into an exception.
    $blocker = sys_get_temp_dir().'/bp-blocker-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');

    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($blocker.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.5,
    );

    $result = runStep($runner, Shell::run('seq 1 100; sleep 3', id: 'unloggable'));

    unlink($blocker);

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->logPath)->toBeNull()
        ->and($result->reason)->toStartWith('Timed out')
        // The summary survived losing the log: 100 is the last line the step
        // printed, and the tail of a truncation is where it lands.
        ->and($result->reason)->toContain('100')
        ->and(substr_count((string) $result->reason, "\n"))->toBeLessThan(30);
});

it('does not read the output of a process that never started', function (): void {
    // A working directory that does not exist fails inside `run()`, at start.
    // Reading output from an unstarted process throws, so this path must stay
    // on the bare message.
    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir().'/bp-absent-'.bin2hex(random_bytes(4)),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
    );

    $result = runStep($runner,
        Shell::run('echo would have run', id: 'no-cwd')->inspecting('true')
    );

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toContain('scope')
        ->and($result->logPath)->toBeNull();
});

it('treats a timeout inside start() as a started process, not a missing one', function (): void {
    // `Process::start()` marks the process started and reads its pipes before it
    // checks the timeout, so a small enough timeout throws from there rather
    // than from `wait()`. In practice the child has not printed anything yet, so
    // what this pins is that the path reads the process at all instead of
    // treating it as never started — which would throw out of the catch.
    //
    // The command must not be able to exit inside that window: `updateStatus()`
    // marks an already-exited process terminated, and `checkTimeout()` returns
    // silently for anything but a started one. `checkTimeout()` calls `stop(0)`
    // before it throws, so this does not wait three seconds.
    $result = runStep($this->runner, Shell::run('sleep 3', id: 'start-timeout')->timeout(0.000001));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toStartWith('Could not run:')
        ->and($result->logPath)->not->toBeNull();
});

it('does not read the output of a step whose process could not be launched', function (): void {
    // The asynchronous path's counterpart: `start()` fails before the process is
    // marked started, so there is nothing to read and reading it would throw.
    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir().'/bp-absent-'.bin2hex(random_bytes(4)),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
    );

    $result = runStep($runner, Shell::run('echo would have run', id: 'unlaunchable'));

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->reason)->toStartWith('Could not run:')
        ->and($result->logPath)->toBeNull();
});

it('says the omitted lines are lost when no log could be written', function (): void {
    // Both features behaving as designed, and the pair losing data: the bound
    // fires and announces what it dropped, and there is nowhere it went. Reached
    // by a read-only mount or a bad owner after a deploy — the same trigger the
    // log-write tolerance exists for — so it lands exactly when the environment
    // is already misbehaving and the output is least reproducible.
    $blocker = sys_get_temp_dir().'/bp-lost-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');

    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($blocker.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.5,
    );

    $result = runStep($runner, Shell::run('seq 1 400; sleep 3', id: 'noisy-unloggable'));

    unlink($blocker);

    expect($result->logPath)->toBeNull()
        ->and($result->reason)->toContain('lines omitted')
        ->and($result->reason)->toContain('No log could be written to')
        // Naming the directory is the actionable half: "no log" alone leaves the
        // reader nothing to fix, and the fix is a path permission.
        ->and($result->reason)->toContain($blocker.'/logs')
        ->and($result->reason)->toContain('the dropped output is lost')
        // The bound still holds. Reporting the loss must not become a reason to
        // send the whole thing.
        ->and(substr_count((string) $result->reason, "\n"))->toBeLessThan(30);
});

it('says nothing about a lost log when the output was not truncated', function (): void {
    // No truncation, no loss to report — the log is missing but nothing was
    // dropped, so the note would be noise on an already-degraded path.
    $blocker = sys_get_temp_dir().'/bp-quiet-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');

    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($blocker.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.5,
    );

    $result = runStep($runner, Shell::run('echo just-one-line; sleep 3', id: 'quiet-unloggable'));

    unlink($blocker);

    expect($result->logPath)->toBeNull()
        ->and($result->reason)->toContain('just-one-line')
        ->and($result->reason)->not->toContain('No log could be written');
});

it('says nothing about a lost log when the log was written', function (): void {
    // Truncated, but the pointer is there — the normal noisy-timeout case, which
    // must not grow a scary sentence about losing output it did not lose. Its own
    // runner because the shared one allows 20s, so `sleep 3` would simply pass.
    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->logDir),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.5,
    );

    $result = runStep($runner, Shell::run('seq 1 400; sleep 3', id: 'noisy-logged'));

    expect($result->logPath)->not->toBeNull()
        ->and($result->reason)->toContain('lines omitted')
        ->and($result->reason)->not->toContain('No log could be written');
});

it('reports a clipped long line as lost, even though no line was omitted', function (): void {
    // The bound that omits nothing. One line over the per-line clamp loses its
    // tail while `truncated` stays false, so a condition on omitted lines alone
    // reported nothing at all — the quietest shape of the same loss.
    $blocker = sys_get_temp_dir().'/bp-long-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');

    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($blocker.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 0.5,
    );

    $result = runStep($runner, Shell::run('printf "%0.sX" $(seq 1 900); sleep 3', id: 'one-long-line'));

    unlink($blocker);

    expect($result->logPath)->toBeNull()
        ->and($result->reason)->not->toContain('lines omitted')
        ->and($result->reason)->toContain('the dropped output is lost');
});

it('names the lost log on an unrunnable command that printed too much', function (): void {
    // Exit 127 takes a different branch from the timeout, and it passes the same
    // log path into the same summariser. Without a case here, dropping that
    // argument would make the reason claim a loss while `logPath` names the file.
    $blocker = sys_get_temp_dir().'/bp-127-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');

    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($blocker.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
    );

    $result = runStep($runner, Shell::run('seq 1 400; definitely-not-a-real-binary-xyz', id: 'noisy-127-unloggable'));

    unlink($blocker);

    expect($result->logPath)->toBeNull()
        ->and($result->reason)->toStartWith('Command did not run (exit 127):')
        ->and($result->reason)->toContain('the dropped output is lost');
});

it('says nothing about a lost log on an unrunnable command that could log', function (): void {
    // The other half: the note must not appear when the path is there, or the
    // reason and `logPath` contradict each other in the same result.
    $result = runStep($this->runner, Shell::run('seq 1 400; definitely-not-a-real-binary-xyz', id: 'noisy-127-logged'));

    expect($result->logPath)->not->toBeNull()
        ->and($result->reason)->toContain('lines omitted')
        ->and($result->reason)->not->toContain('No log could be written');
});

it('names the lost log when a noisy scope command could not be logged', function (): void {
    // The third call site that passes a log path into the summariser. Without a
    // case here, dropping that argument makes the reason claim a loss on a run
    // whose log exists — and the test above only proves the opposite direction.
    $blocker = sys_get_temp_dir().'/bp-scope-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');

    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($blocker.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
    );

    $result = runStep($runner,
        Shell::run('echo would have run', id: 'noisy-scope-unloggable')->inspecting('seq 1 400; exit 4')
    );

    unlink($blocker);

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($result->logPath)->toBeNull()
        ->and($result->reason)->toContain('scope')
        ->and($result->reason)->toContain('the dropped output is lost');
});

it('names the lost log on a step that passed but could not be logged', function (): void {
    // A pass drops output the same way a failure does. Nothing needs diagnosing
    // here, but the summary is still offered as the step's output and was
    // silently incomplete.
    $blocker = sys_get_temp_dir().'/bp-pass-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'not a directory');

    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($blocker.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
    );

    $result = runStep($runner, Shell::run('seq 1 400', id: 'noisy-pass-unloggable'));

    unlink($blocker);

    expect($result->verdict)->toBe(Verdict::Passed)
        ->and($result->logPath)->toBeNull()
        ->and($result->summary)->toContain('the dropped output is lost');
});

it('says nothing about a lost log on a quiet passing step', function (): void {
    // The note must not appear on the ordinary case, which is every passing step
    // in a working project.
    $result = runStep($this->runner, Shell::run('echo all good', id: 'quiet-pass'));

    expect($result->verdict)->toBe(Verdict::Passed)
        ->and($result->summary)->not->toContain('No log could be written');
});

it('says nothing about a lost log on a noisy passing step that could log', function (): void {
    // The opposite direction of the unloggable pass. Without it, cutting the log
    // path out of describePass() would make every noisy pass claim a loss while
    // its log sits on disk, and no passing-step test would notice.
    $result = runStep($this->runner, Shell::run('seq 1 400', id: 'noisy-pass-logged'));

    expect($result->verdict)->toBe(Verdict::Passed)
        ->and($result->logPath)->not->toBeNull()
        ->and($result->summary)->not->toContain('No log could be written');
});
