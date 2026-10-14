<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Contracts\BackgroundStepRunner;
use SanderMuller\BoostPipeline\Contracts\BatchStepRunner;
use SanderMuller\BoostPipeline\Contracts\PendingSteps;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;
use WeakMap;

/**
 * Executes a shell step in a subprocess and owns the verdict.
 *
 * The one invariant: a tool that did NOT run never yields a pass. "Ran and found
 * nothing" and "did not run" are different outcomes, so a missing binary, a
 * timeout and a thrown exception all produce Verdict::Error, never Failed and
 * never Passed.
 */
final readonly class ProcessStepRunner implements BackgroundStepRunner, BatchStepRunner
{
    /**
     * Under the MCP per-call wall clock, which the spec pins at 600000ms in
     * `.mcp.json`. A step allowed to outlive that would be killed by the client
     * mid-run, losing the verdict, so this default stays comfortably below it.
     */
    public const float DEFAULT_TIMEOUT_SECONDS = 540.0;

    private const float SCOPE_TIMEOUT_SECONDS = 60.0;

    /** Past this share of its timeout, a step that still finished is reported as close to the edge. */
    private const float HEADROOM_WARNING_RATIO = 0.8;

    /** Shell conventions: 127 = not found, 126 = found but not executable. */
    private const array UNRUNNABLE_EXIT_CODES = [126, 127];

    /**
     * The files a background process writes its output to, by process.
     *
     * @var WeakMap<Process, array{string, string}>
     */
    private WeakMap $redirects;

    public function __construct(
        private string $workingDirectory,
        private LogWriter $logs,
        private OutputSummariser $summariser,
        private EnvironmentScrubber $environment,
        private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->redirects = new WeakMap;
    }

    /**
     * The ceiling this runner would enforce for a step, or null when it enforces none.
     *
     * Only this runner can answer: the `StepRunner` contract declares no timeout,
     * and a step's own value lives on `Shell` rather than the `Step` interface. So
     * a custom runner records no ceiling, and {@see LiveProgress::hasExpired()}
     * then never ages its record out — the honest answer rather than a guess made
     * with this runner's default.
     */
    public function effectiveTimeout(Step $step): ?float
    {
        return $step instanceof Shell ? ($step->timeoutSeconds() ?? $this->timeoutSeconds) : null;
    }

    public function run(Step $step, string $runId): Result
    {
        if ($step->kind() !== StepKind::Shell || ! $step instanceof Shell) {
            // A skill step is resolved by the agent acknowledging it, never here.
            return Result::error($step->id(), 'Only shell steps can be executed by the server.');
        }

        return $this->execute($step, $runId);
    }

    /**
     * Runs every step at once, then collects the verdicts in declaration order.
     *
     * Scope commands stay sequential. They are capped at a minute and usually
     * take milliseconds, and keeping them out of the concurrent path keeps the
     * part that can go wrong small.
     *
     * @param  list<Step>  $steps
     * @return array<string, Result>
     */
    public function runBatch(array $steps, string $runId): array
    {
        // Polled together rather than waited on in order. Waiting in order timed
        // a fast step by its slowest sibling, so every step in the group read as
        // close to its timeout.
        return $this->begin($steps, $runId)->await(INF) ?? [];
    }

    /**
     * Starts every step and returns at once; {@see PendingSteps::await()} collects.
     *
     * Each process writes its output to two files instead of to pipes. Between two
     * tool calls nothing reads a pipe, so a suite printing more than the pipe
     * buffer would block on its own output until the agent called again — and its
     * timeout would keep counting while it sat there. Two files, not one, so the
     * output keeps stdout and stderr apart exactly as a pipe-read step does.
     *
     * Scope commands still run here, before this returns. They are capped at a
     * minute and usually take milliseconds.
     *
     * @param  list<Step>  $steps
     */
    public function begin(array $steps, string $runId): PendingSteps
    {
        $settled = [];
        $running = [];

        foreach ($steps as $step) {
            if ($step->kind() !== StepKind::Shell || ! $step instanceof Shell) {
                $settled[$step->id()] = Result::error($step->id(), 'Only shell steps can be executed by the server.');

                continue;
            }

            $scope = $this->resolveScope($step, $runId);

            if ($scope instanceof Result) {
                $settled[$step->id()] = $scope;

                continue;
            }

            // Windows runs the command through cmd.exe, which has no `exec` to
            // redirect with, so output stays on pipes there. A long background
            // step can then block on a full pipe between calls.
            $windows = DIRECTORY_SEPARATOR === '\\';
            $files = $windows ? null : $this->outputFiles();

            if (! $windows && $files === null) {
                $settled[$step->id()] = Result::error($step->id(), sprintf('Could not run: no file for its output could be created in %s.', sys_get_temp_dir()));

                continue;
            }

            $timeout = $step->timeoutSeconds() ?? $this->timeoutSeconds;
            $process = $this->start($step, $timeout, $runId, $files);
            $discard = static function () use ($files): void {
                if ($files !== null) {
                    @unlink($files[0]);
                    @unlink($files[1]);
                }
            };

            if ($process instanceof Result) {
                $settled[$step->id()] = $process;
                $discard();

                continue;
            }

            $running[$step->id()] = [
                $process,
                function (?Throwable $failure, float $seenAt, bool $late) use ($step, $process, $scope, $runId, $timeout, $discard): Result {
                    try {
                        return match (true) {
                            ! $failure instanceof Throwable => $this->verdictFor($step, $process, $scope, $runId, $timeout, $seenAt, $late),
                            $failure instanceof ProcessTimedOutException => $this->preserving($process, $step->id(), $runId, "Timed out after {$timeout}s."),
                            default => $this->preserving($process, $step->id(), $runId, "Could not run: {$failure->getMessage()}"),
                        };
                    } finally {
                        $discard();
                    }
                },
                $discard,
            ];
        }

        return new ProcessPendingSteps(
            array_map(static fn (Step $step): string => $step->id(), $steps),
            $settled,
            $running,
        );
    }

    /** @return array{string, string}|null */
    private function outputFiles(): ?array
    {
        $out = @tempnam(sys_get_temp_dir(), 'bp-out-');
        $err = @tempnam(sys_get_temp_dir(), 'bp-err-');

        if ($out === false || $err === false) {
            if (is_string($out)) {
                @unlink($out);
            }

            if (is_string($err)) {
                @unlink($err);
            }

            return null;
        }

        return [$out, $err];
    }

    private function execute(Shell $step, string $runId): Result
    {
        $scope = $this->resolveScope($step, $runId);

        if ($scope instanceof Result) {
            return $scope;
        }

        $timeout = $step->timeoutSeconds() ?? $this->timeoutSeconds;

        // The command ALWAYS runs. An empty scope annotates the verdict; it never
        // replaces it. Short-circuiting here would mean a typo'd scope glob, or a
        // scope command that silently matches nothing, permanently disables the
        // gate — a false green of exactly the kind this pipeline exists to stop.
        $process = $this->start($step, $timeout, $runId);

        if ($process instanceof Result) {
            return $process;
        }

        $timedOut = $this->settle($process, $step->id(), $timeout, $runId);

        if ($timedOut instanceof Result) {
            return $timedOut;
        }

        return $this->verdictFor($step, $process, $scope, $runId, $timeout);
    }

    /**
     * @param  float|null  $finishedAt  when the process was first seen finished, null for now
     * @param  bool  $measuredLate  it was first seen after a gap with no poll, so its duration is an upper bound
     */
    private function verdictFor(Shell $step, Process $process, ?int $scope, string $runId, float $timeout, ?float $finishedAt = null, bool $measuredLate = false): Result
    {
        $headroom = $this->headroomNote($process, $timeout, $finishedAt ?? microtime(true), $measuredLate);

        $output = $this->combinedOutput($process);
        $logPath = $this->logs->write($runId, $step->id(), $output);
        $exitCode = $process->getExitCode() ?? 1;

        if (in_array($exitCode, self::UNRUNNABLE_EXIT_CODES, true)) {
            return Result::error(
                $step->id(),
                sprintf('Command did not run (exit %d): %s', $exitCode, $this->summarised($output, $logPath)),
                logPath: $logPath,
            );
        }

        $summary = $this->summariser->summarise($output);

        if ($exitCode !== 0) {
            return Result::failed(
                $step->id(),
                $this->describeFailure($summary, $logPath).$headroom,
                $exitCode,
                $logPath,
                $scope,
            );
        }

        return Result::passed(
            $step->id(),
            $this->describePass($summary, $scope, $logPath).$headroom,
            $scope,
            $logPath,
        );
    }

    /**
     * A note when a step finished, but used most of its timeout.
     *
     * A step that took 95s of a 100s ceiling and passed says nothing about the
     * next run, which can cross it and halt. Said on its own line, after the
     * output, because the summary above it is what the step printed.
     *
     * A background step is timed at the poll that first saw it finished. After a
     * gap between two tool calls that can be long after it exited, and the note
     * then says the figure is an upper bound rather than the step's duration.
     */
    private function headroomNote(Process $process, float $timeout, float $finishedAt, bool $measuredLate): string
    {
        $elapsed = $finishedAt - $process->getStartTime();

        // Zero is Symfony's "no timeout", so there is no ceiling to be close to.
        if ($timeout <= 0.0 || $elapsed <= $timeout * self::HEADROOM_WARNING_RATIO) {
            return '';
        }

        return sprintf(
            "\n\nTook %s%.0fs of its %.0fs timeout (%d%%)%s. A slower run will time out: raise this step's ->timeout(), or the pipeline's ->withTimeout().",
            $measuredLate ? 'up to ' : '',
            $elapsed,
            $timeout,
            (int) floor($elapsed / $timeout * 100),
            $measuredLate ? ', measured when the server next checked, so the real time can be shorter' : '',
        );
    }

    /**
     * The number of files the step declared it would inspect, null when it
     * declared no scope, or an error Result when the scope cannot be computed.
     *
     * A declared-but-uncomputable scope is a broken config, not "unknown": if the
     * scope command cannot run, treating it as zero would hand back a pass.
     */
    private function resolveScope(Shell $step, string $runId): int|null|Result
    {
        $command = $step->scopeCommand();

        if ($command === null) {
            return null;
        }

        $process = $this->process($step->id(), $runId, $command, self::SCOPE_TIMEOUT_SECONDS, $step->env());

        if ($process instanceof Result) {
            return Result::error(
                $step->id(),
                "Could not determine declared scope: {$process->summary}",
                logPath: $process->logPath,
            );
        }

        if ($process->getExitCode() !== 0) {
            $output = $this->combinedOutput($process);
            $logPath = $this->logs->write($runId, $step->id(), $output);

            return Result::error($step->id(), sprintf(
                "Scope command exited %d, so the step's scope is unknown: %s",
                $process->getExitCode() ?? 1,
                $this->summarised($output, $logPath),
            ), logPath: $logPath);
        }

        return count($this->nonEmptyLines($process->getOutput()));
    }

    /**
     * Started but not waited on, so a caller can have several running at once.
     *
     * @param  array{string, string}|null  $files  where stdout and stderr go instead of to pipes
     */
    private function start(Shell $step, float $timeout, string $runId, ?array $files = null): Process|Result
    {
        try {
            $process = $this->processFor(
                $files === null
                    ? $step->command()
                    // `exec` with no command only redirects the shell's own
                    // descriptors. The shell then execs the step's command as
                    // its last command, so a stop or a timeout signals the
                    // command itself, as it does without the redirect. The
                    // runner tests pin this with a command that would leave a
                    // marker if only the outer shell were killed.
                    : sprintf("exec >%s 2>%s\n%s", escapeshellarg($files[0]), escapeshellarg($files[1]), $step->command()),
                $timeout,
                $step->env(),
            );

            if ($files !== null) {
                $this->redirects[$process] = $files;
            }
        } catch (Throwable $throwable) {
            // Nothing was built, so there is no captured output to preserve.
            return Result::error($step->id(), "Could not run: {$throwable->getMessage()}");
        }

        try {
            $process->start();

            return $process;
        } catch (Throwable $throwable) {
            // start() marks the process started and reads its pipes BEFORE it
            // checks the timeout, so a small enough timeout throws here with
            // output already buffered.
            return $process->isStarted()
                ? $this->preserving($process, $step->id(), $runId, "Could not run: {$throwable->getMessage()}")
                : Result::error($step->id(), "Could not run: {$throwable->getMessage()}");
        }
    }

    /** A Result when the step never produced a verdict, null when it did. */
    private function settle(Process $process, string $stepId, float $timeout, string $runId): ?Result
    {
        try {
            $process->wait();

            return null;
        } catch (ProcessTimedOutException) {
            return $this->preserving($process, $stepId, $runId, "Timed out after {$timeout}s.");
        } catch (Throwable $exception) {
            return $this->preserving($process, $stepId, $runId, "Could not run: {$exception->getMessage()}");
        }
    }

    /** @param array<string, string> $env */
    private function process(string $stepId, string $runId, string $command, float $timeout, array $env = []): Process|Result
    {
        try {
            $process = $this->processFor($command, $timeout, $env);
        } catch (Throwable $throwable) {
            // Nothing was built, so there is no captured output to preserve.
            return Result::error($stepId, "Could not run: {$throwable->getMessage()}");
        }

        try {
            $process->run();

            return $process;
        } catch (ProcessTimedOutException) {
            return $this->preserving($process, $stepId, $runId, "Timed out after {$timeout}s.");
        } catch (Throwable $exception) {
            // run() is start() plus wait(). A start() failure leaves the process
            // unstarted, and reading its output would throw out of this catch.
            return $process->isStarted()
                ? $this->preserving($process, $stepId, $runId, "Could not run: {$exception->getMessage()}")
                : Result::error($stepId, "Could not run: {$exception->getMessage()}");
        }
    }

    /** @param array<string, string> $env */
    private function processFor(string $command, float $timeout, array $env): Process
    {
        return Process::fromShellCommandline(
            $command,
            $this->workingDirectory,
            $this->environment->forStep($env),
            timeout: $timeout,
        );
    }

    private function combinedOutput(Process $process): string
    {
        $files = $this->redirects[$process] ?? null;

        return implode("\n", array_filter([
            rtrim($files === null ? $process->getOutput() : $this->contents($files[0])),
            rtrim($files === null ? $process->getErrorOutput() : $this->contents($files[1])),
        ], static fn (string $part): bool => $part !== ''));
    }

    private function contents(string $path): string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /** @param array{summary: string, output_lines: int, shown_lines: int, truncated: bool, clipped: bool} $summary */
    private function describePass(array $summary, ?int $scope, ?string $logPath): string
    {
        $text = $this->orElse($summary['summary'], 'Passed.');

        // A pass drops output the same way a failure does. Nothing needs
        // diagnosing when a step passed, but the summary is still offered as the
        // step's output, and offering an incomplete one silently is the thing
        // being fixed — not the severity of the step it happened on.
        if (($summary['truncated'] || $summary['clipped']) && $logPath === null) {
            $text .= "\n\n".$this->lostLog();
        }

        if ($scope !== 0) {
            return $text;
        }

        return "Inspected 0 files: this step is scoped to a set that is currently empty, so it passed without proving anything.\n\n".$text;
    }

    /** @param array{summary: string, output_lines: int, shown_lines: int, truncated: bool, clipped: bool} $summary */
    private function describeFailure(array $summary, ?string $logPath): string
    {
        $text = $this->orElse($summary['summary'], 'Failed with no output.');

        if (! $summary['truncated']) {
            // Same loss, no omitted line to count: a clipped long line still went
            // somewhere, and nowhere when the log could not be written.
            return $summary['clipped'] && $logPath === null
                ? $text."\n\n".$this->lostLog()
                : $text;
        }

        $remaining = $summary['output_lines'] - $summary['shown_lines'];

        return $text."\n\n".sprintf(
            '… %d more line(s)%s',
            $remaining,
            $logPath === null ? '. '.$this->lostLog() : " — full output at {$logPath}",
        );
    }

    /** @return list<string> */
    private function nonEmptyLines(string $output): array
    {
        return array_values(array_filter(
            $this->splitLines($output),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    /** @return list<string> */
    private function splitLines(string $output): array
    {
        $lines = preg_split('/\R/', trim($output));

        return $lines === false ? [] : $lines;
    }

    /**
     * An error verdict that keeps what the process had already printed.
     *
     * These paths produce no verdict, so this summary is the only diagnostic the
     * agent sees, and the full text goes to the log in the same step. A failed
     * log write does NOT widen the summary: the MCP payload has a hard ceiling
     * that raw output would blow, so it stays bounded either way.
     */
    private function preserving(Process $process, string $stepId, string $runId, string $message): Result
    {
        $output = $this->combinedOutput($process);
        $logPath = $this->logs->write($runId, $stepId, $output);

        return Result::error(
            $stepId,
            $message.' '.$this->summarised($output, $logPath),
            logPath: $logPath,
        );
    }

    /**
     * @param  string|null  $logPath  where the full output went, when it went anywhere
     */
    private function summarised(string $output, ?string $logPath = null): string
    {
        $summary = $this->summariser->summarise($output);
        $text = $this->orElse(trim($summary['summary']), 'no output');

        // Truncated with nowhere to truncate to. The bound stays — unbounded
        // output on a halting path is the worse trade — but silently discarding
        // what it counted is not something the reader can be left to infer from
        // an absent path. Both halves behave as designed and the pair loses data,
        // so the pair is what gets reported.
        // `clipped` as well as `truncated`: a single very long line is dropped by
        // the byte cap or the per-line clamp while omitting no line at all, so a
        // condition on omitted lines alone stays silent on real loss.
        if (($summary['truncated'] || $summary['clipped']) && $logPath === null) {
            // Its own line: the summary's tail IS program output, so a note glued
            // to it reads as one more line the step printed.
            $text .= "\n".$this->lostLog();
        }

        return $text;
    }

    /**
     * Said wherever output was dropped and no log holds it.
     *
     * The directory is the actionable half: "no log could be written" alone
     * leaves the reader nothing to fix, and the fix is a path permission — a
     * read-only mount or a bad owner after a deploy, which is the trigger the
     * write tolerance already exists for.
     */
    private function lostLog(): string
    {
        return sprintf('No log could be written to %s, so the dropped output is lost.', $this->logs->directory());
    }

    private function orElse(string $value, string $fallback): string
    {
        return $value === '' ? $fallback : $value;
    }
}
